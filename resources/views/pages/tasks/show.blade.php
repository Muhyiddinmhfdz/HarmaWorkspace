<?php

use App\Domain\Task\Actions\ChangeTaskSchedule;
use App\Domain\Task\Actions\ManageTaskAssignments;
use App\Domain\Task\Actions\UpdateTaskProgress;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Support\TransitionRules;
use App\Domain\Task\Support\TaskWeight;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithWorkflowTransitions;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use App\Models\Status;
use App\Models\Task;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use WithFeedback, WithWorkflowTransitions;

    #[Locked]
    public int $taskId;

    public int $progress = 0;

    public string $scheduleStart = '';

    public string $scheduleEnd = '';

    public string $scheduleReason = '';

    #[Locked]
    public ?int $blockStatusId = null;

    public string $blockReason = '';

    public ?int $newPicId = null;

    public ?int $collaboratorToAdd = null;

    public function mount(Task $task): void
    {
        $this->authorize('view', $task);
        $this->taskId = $task->id;
    }

    #[On('task-updated')]
    public function refreshTask(): void
    {
        unset($this->task, $this->can, $this->transitions, $this->pendingTransition);
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()
            ->with([
                'project.status',
                'milestone:id,name,current_due_date',
                'type:id,name',
                'category:id,name',
                'status',
                'priority',
                'department:id,name',
                'blocker:id,name',
                'creator:id,name',
                'picAssignment.user:id,name,username',
                'collaboratorAssignments.user:id,name,username',
            ])
            ->findOrFail($this->taskId);
    }

    /**
     * @return array<string, bool>
     */
    #[Computed]
    public function can(): array
    {
        $user = auth()->user();
        $task = $this->task;

        // Tasks of archived/finished projects are read-only for everyone,
        // including the Super Admin bypass.
        if ($task->project !== null && ($task->project->isArchived() || $task->project->status->is_terminal)) {
            return array_fill_keys(['update', 'assign', 'schedule', 'work', 'complete', 'cancel'], false);
        }

        return [
            'update' => $user->can('update', $task),
            'assign' => $user->can('assign', $task),
            'schedule' => $user->can('manageSchedule', $task),
            'work' => $user->can('work', $task),
            'complete' => $user->can('complete', $task),
            'cancel' => $user->can('cancel', $task),
        ];
    }

    protected function workflowRecord(): Model
    {
        return $this->task;
    }

    protected function afterWorkflowTransition(): void
    {
        $this->afterChange();
    }

    /**
     * Actions from the task's workflow version the user may run now
     * (no free status dropdown). Read-only for archived/finished projects.
     *
     * @return list<array{id: int, status: Status, label: string, kind: string, confirm: string|null}>
     */
    #[Computed]
    public function transitions(): array
    {
        $task = $this->task;

        if ($task->project !== null && ($task->project->isArchived() || $task->project->status->is_terminal)) {
            return [];
        }

        return array_map(fn (WorkflowTransition $transition): array => [
            'id' => $transition->id,
            'status' => $transition->toState->status,
            'label' => $transition->action_label,
            'confirm' => $transition->confirmation_message,
            'kind' => match (true) {
                $transition->requiresApproval() => 'approval',
                $transition->toState->status->is_blocked => 'block',
                $transition->toState->status->counts_as_completed => 'complete',
                $transition->toState->status->is_terminal => 'cancel',
                default => 'normal',
            },
        ], $this->availableTransitions());
    }

    /**
     * @return list<int>|null
     */
    #[Computed]
    public function assignableIds(): ?array
    {
        $project = $this->task->project;

        if ($project === null) {
            return null;
        }

        return array_values(array_unique([$project->project_manager_id, ...array_map(intval(...), $project->members()->pluck('user_id')->all())]));
    }

    /**
     * Move to a target status through the workflow (kept for status-based
     * callers): blocked targets ask for the blocker reason first.
     */
    public function moveToStatus(int $statusId): void
    {
        $transition = $this->outgoingTransition(fn (WorkflowTransition $candidate): bool => $candidate->toState->status_id === $statusId);

        if ($transition === null) {
            $target = Status::query()->findOrFail($statusId);

            throw new BusinessRuleException('Status Task tidak dapat diubah dari '.$this->task->status->name.' ke '.$target->name.'.');
        }

        if ($transition->toState->status->is_blocked && ! $transition->requiresApproval()) {
            $this->authorize('view', $this->task);
            abort_unless(app(TransitionRules::class)->canPerform(auth()->user(), $this->task, $transition), 403);

            $this->blockStatusId = $statusId;
            $this->blockReason = '';
            $this->resetValidation();
            Flux::modal('task-block')->show();

            return;
        }

        $this->startTransition($transition->id);
    }

    public function block(): void
    {
        $this->validate(['blockReason' => ['required', 'string', 'min:5', 'max:1000']], attributes: ['blockReason' => 'Reason']);

        $transition = $this->outgoingTransition(fn (WorkflowTransition $candidate): bool => $candidate->toState->status_id === $this->blockStatusId)
            ?? throw new BusinessRuleException('Status sudah berubah. Muat ulang data terbaru.');

        $this->runTransition($transition->id, ['reason' => $this->blockReason]);

        Flux::modal('task-block')->close();
    }

    public function openProgress(): void
    {
        abort_unless($this->can['work'], 403);
        $this->progress = $this->task->progress_percentage;
        Flux::modal('task-progress')->show();
    }

    public function saveProgress(UpdateTaskProgress $updateProgress): void
    {
        $this->authorize('work', $this->task);
        $this->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $updateProgress->handle($this->task, $this->progress, auth()->user());

        Flux::modal('task-progress')->close();
        $this->notify('success', 'Progress task diperbarui menjadi '.$this->progress.'%.');
        $this->afterChange();
    }

    public function openSchedule(): void
    {
        abort_unless($this->can['schedule'], 403);
        $this->scheduleStart = (string) $this->task->current_start?->toDateString();
        $this->scheduleEnd = (string) $this->task->current_end?->toDateString();
        $this->scheduleReason = '';
        $this->resetValidation();
        Flux::modal('task-schedule')->show();
    }

    public function saveSchedule(ChangeTaskSchedule $changeSchedule): void
    {
        $this->authorize('manageSchedule', $this->task);
        $hasBaseline = $this->task->planned_start !== null || $this->task->planned_end !== null;

        $this->validate([
            'scheduleStart' => ['nullable', 'date'],
            'scheduleEnd' => ['nullable', 'date', 'after_or_equal:scheduleStart'],
            'scheduleReason' => [$hasBaseline ? 'required' : 'nullable', 'string', 'max:1000'],
        ], attributes: ['scheduleStart' => 'Start date', 'scheduleEnd' => 'Deadline', 'scheduleReason' => 'Reason']);

        $changeSchedule->handle($this->task, $this->scheduleStart ?: null, $this->scheduleEnd ?: null, $this->scheduleReason ?: null, auth()->user());

        Flux::modal('task-schedule')->close();
        $this->notify('success', 'Jadwal task diperbarui.');
        $this->afterChange();
    }

    public function openPic(): void
    {
        abort_unless($this->can['assign'], 403);
        $this->newPicId = $this->task->picAssignment?->user_id;
        Flux::modal('task-pic')->show();
    }

    public function savePic(ManageTaskAssignments $assignments): void
    {
        $this->authorize('assign', $this->task);
        $this->validate(['newPicId' => ['required', 'integer']], ['newPicId.required' => 'PIC wajib dipilih.']);

        $assignments->setPic($this->task, (int) $this->newPicId, auth()->user());

        Flux::modal('task-pic')->close();
        $this->notify('success', 'PIC task diperbarui.');
        $this->afterChange();
    }

    public function updatedCollaboratorToAdd(?int $userId): void
    {
        $assignments = app(ManageTaskAssignments::class);

        if ($userId === null) {
            return;
        }

        $this->authorize('assign', $this->task);
        $this->collaboratorToAdd = null;

        $assignments->addCollaborator($this->task, $userId, auth()->user());

        $this->notify('success', 'Kolaborator ditambahkan.');
        $this->afterChange();
    }

    public function removeCollaborator(int $assignmentId, ManageTaskAssignments $assignments): void
    {
        $this->authorize('assign', $this->task);

        $assignments->removeCollaborator($this->task, $assignmentId, auth()->user());

        $this->notify('success', 'Kolaborator dikeluarkan. Riwayatnya tetap tersimpan.');
        $this->afterChange();
    }

    private function afterChange(): void
    {
        $this->refreshTask();
        $this->dispatch('task-updated');
    }

    public function render()
    {
        return $this->view()->title($this->task->number.' · '.$this->task->title);
    }
}; ?>

@php($task = $this->task)
@php($can = $this->can)

<div class="mx-auto w-full max-w-6xl">
    {{-- Header --}}
    <div class="mb-6">
        <flux:breadcrumbs class="mb-3">
            @if ($task->project)
                <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('projects.show', ['project' => $task->project, 'tab' => 'tasks'])" wire:navigate>{{ $task->project->name }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item :href="route('tasks.index')" wire:navigate>Tasks</flux:breadcrumbs.item>
            @endif
            <flux:breadcrumbs.item>{{ $task->number }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="font-mono text-sm text-zinc-500">{{ $task->number }}</div>
                <flux:heading size="xl" level="1" class="mt-0.5">{{ $task->title }}</flux:heading>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.master-badge :master="$task->status" />
                    <x-ui.master-badge :master="$task->priority" />
                    @if ($task->isOverdue())
                        <flux:badge size="sm" color="red" icon="clock">Overdue</flux:badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($can['update'])
                    <flux:button icon="pencil-square" :href="route('tasks.edit', $task)" wire:navigate>Edit</flux:button>
                @endif
                @if ($this->transitions !== [] || $can['work'] || $can['schedule'])
                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon:trailing="chevron-down">More</flux:button>
                        <flux:menu>
                            @foreach ($this->transitions as $transition)
                                @switch($transition['kind'])
                                    @case('cancel')
                                        <flux:menu.item icon="x-circle" variant="danger" wire:key="tr-{{ $transition['id'] }}"
                                            x-on:click="hwConfirm({ title: @js($transition['label'].'?'), text: @js($transition['confirm'] ?? 'Task tidak lagi dihitung dalam progress project. Riwayatnya tetap tersimpan.'), confirmText: @js($transition['label']), cancelText: 'Kembali', danger: true }).then(ok => ok && $wire.startTransition({{ $transition['id'] }}))">
                                            {{ $transition['label'] }}
                                        </flux:menu.item>
                                        @break
                                    @case('complete')
                                        <flux:menu.item icon="check-badge" wire:key="tr-{{ $transition['id'] }}"
                                            x-on:click="hwConfirm({ title: @js($transition['label'].'?'), text: @js($transition['confirm'] ?? 'Progress menjadi 100% dan tanggal selesai aktual dicatat.'), confirmText: @js($transition['label']) }).then(ok => ok && $wire.startTransition({{ $transition['id'] }}))">
                                            {{ $transition['label'] }}
                                        </flux:menu.item>
                                        @break
                                    @case('block')
                                        <flux:menu.item icon="no-symbol" wire:key="tr-{{ $transition['id'] }}" wire:click="moveToStatus({{ $transition['status']->id }})">{{ $transition['label'] }}</flux:menu.item>
                                        @break
                                    @case('approval')
                                        <flux:menu.item icon="shield-check" wire:key="tr-{{ $transition['id'] }}" wire:click="startTransition({{ $transition['id'] }})">{{ $transition['label'] }} <span class="ms-1 text-xs text-zinc-400">(approval)</span></flux:menu.item>
                                        @break
                                    @default
                                        @if ($transition['confirm'])
                                            <flux:menu.item icon="arrow-right-circle" wire:key="tr-{{ $transition['id'] }}"
                                                x-on:click="hwConfirm({ title: @js($transition['label'].'?'), text: @js($transition['confirm']), confirmText: @js($transition['label']) }).then(ok => ok && $wire.startTransition({{ $transition['id'] }}))">{{ $transition['label'] }}</flux:menu.item>
                                        @else
                                            <flux:menu.item icon="arrow-right-circle" wire:key="tr-{{ $transition['id'] }}" wire:click="startTransition({{ $transition['id'] }})">{{ $transition['label'] }}</flux:menu.item>
                                        @endif
                                @endswitch
                            @endforeach
                            @if ($this->transitions !== [] && ($can['work'] || $can['schedule']))
                                <flux:menu.separator />
                            @endif
                            @if ($can['work'] && ! $task->status->is_terminal)
                                <flux:menu.item icon="chart-bar" wire:click="openProgress">Update progress</flux:menu.item>
                            @endif
                            @if ($can['schedule'])
                                <flux:menu.item icon="calendar-days" wire:click="openSchedule">Change schedule</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <div class="mt-4 max-w-md">
            <div class="mb-1 flex items-center justify-between text-sm">
                <span class="text-zinc-500">Progress</span>
                <span class="font-semibold tabular-nums text-zinc-900">{{ $task->progress_percentage }}% complete</span>
            </div>
            <x-ui.progress-bar :value="$task->progress_percentage" :show-label="false" />
        </div>
    </div>

    @if ($task->status->is_blocked)
        <flux:callout variant="danger" icon="no-symbol" class="mb-6" heading="Blocked">
            <flux:callout.text>
                {{ $task->blocked_reason }}
                <span class="mt-1 block text-xs">Blocked by {{ $task->blocker->name ?? 'System' }} · {{ $task->blocked_at?->translatedFormat('d M Y H:i') }}</span>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Main --}}
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <flux:card>
                <flux:heading size="lg">Description</flux:heading>
                <flux:text class="mt-2 whitespace-pre-line">{{ $task->description ?: 'Belum ada deskripsi.' }}</flux:text>
            </flux:card>

            <livewire:approvals.progress subject-type="task" :subject-id="$task->id" wire:key="approval-progress-{{ $task->id }}" />

            <livewire:tasks.subtasks :task-id="$task->id" wire:key="subtasks-{{ $task->id }}" />

            <livewire:tasks.checklist owner-type="task" :owner-id="$task->id" wire:key="checklist-{{ $task->id }}" />

            <livewire:tickets.related :task-id="$task->id" wire:key="related-tickets-{{ $task->id }}" />

            <livewire:tasks.activity :task-id="$task->id" wire:key="activity-{{ $task->id }}" />
        </div>

        {{-- Properties --}}
        <aside class="h-fit rounded-xl border border-zinc-200 bg-white">
            <dl class="divide-y divide-zinc-100 px-5">
                <div class="py-3">
                    <dt class="text-xs text-zinc-500">Main PIC</dt>
                    <dd class="mt-1 flex items-center justify-between gap-2">
                        @if ($task->picAssignment)
                            <span class="flex min-w-0 items-center gap-2">
                                <flux:avatar size="xs" :name="$task->picAssignment->user->name" :initials="$task->picAssignment->user->initials()" />
                                <span class="truncate text-sm font-medium text-zinc-900">{{ $task->picAssignment->user->name }}</span>
                            </span>
                        @else
                            <span class="text-sm text-zinc-400">Belum ditentukan</span>
                        @endif
                        @if ($can['assign'])
                            <flux:button size="xs" variant="ghost" wire:click="openPic">{{ $task->picAssignment ? 'Change' : 'Assign' }}</flux:button>
                        @endif
                    </dd>
                </div>
                <div class="py-3">
                    <dt class="text-xs text-zinc-500">Collaborators</dt>
                    <dd class="mt-1 space-y-1">
                        @forelse ($task->collaboratorAssignments as $assignment)
                            <div wire:key="col-{{ $assignment->id }}" class="flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-2">
                                    <flux:avatar size="xs" :name="$assignment->user->name" :initials="$assignment->user->initials()" />
                                    <span class="truncate text-sm text-zinc-800">{{ $assignment->user->name }}</span>
                                </span>
                                @if ($can['assign'])
                                    <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="Remove {{ $assignment->user->name }}"
                                        x-on:click="hwConfirm({ title: 'Keluarkan kolaborator?', text: @js($assignment->user->name.' tidak lagi menjadi kolaborator task ini. Riwayatnya tetap tersimpan.'), confirmText: 'Keluarkan', danger: true }).then(ok => ok && $wire.removeCollaborator({{ $assignment->id }}))" />
                                @endif
                            </div>
                        @empty
                            <span class="text-sm text-zinc-400">Belum ada kolaborator</span>
                        @endforelse
                        @if ($can['assign'])
                            <div class="pt-1">
                                <livewire:user-picker wire:model.live="collaboratorToAdd" label="" placeholder="Tambah kolaborator..." :only="$this->assignableIds"
                                    :exclude="array_values(array_filter([$task->picAssignment?->user_id, ...$task->collaboratorAssignments->pluck('user_id')->all()]))"
                                    :key="'collab-'.$task->collaboratorAssignments->count().'-'.$task->picAssignment?->user_id" />
                            </div>
                        @endif
                    </dd>
                </div>
                <x-ui.detail-item label="Department" class="py-3 sm:flex-col sm:gap-1">{{ $task->department?->name }}</x-ui.detail-item>
                <x-ui.detail-item label="Project" class="py-3 sm:flex-col sm:gap-1">
                    @if ($task->project)
                        <a href="{{ route('projects.show', $task->project) }}" wire:navigate class="hover:underline">{{ $task->project->name }}</a>
                    @else
                        Standalone task
                    @endif
                </x-ui.detail-item>
                @if ($task->project)
                    <x-ui.detail-item label="Milestone" class="py-3 sm:flex-col sm:gap-1">{{ $task->milestone?->name }}</x-ui.detail-item>
                @endif
                <div class="py-3">
                    <dt class="flex items-center justify-between text-xs text-zinc-500">
                        Schedule
                        @if ($can['schedule'])
                            <flux:button size="xs" variant="ghost" wire:click="openSchedule">Change</flux:button>
                        @endif
                    </dt>
                    <dd class="mt-1 space-y-1 text-sm">
                        @if ($task->current_end)
                            <x-ui.deadline :date="$task->current_end" :done="$task->status->is_terminal" />
                        @else
                            <span class="text-zinc-400">Belum ada deadline</span>
                        @endif
                        @if ($task->planned_end && $task->current_end && ! $task->planned_end->equalTo($task->current_end))
                            <div class="text-xs text-zinc-500">Rencana awal {{ $task->planned_end->translatedFormat('d M Y') }}</div>
                        @endif
                        @if ($task->actual_start || $task->actual_end)
                            <div class="text-xs text-zinc-500">Aktual: {{ $task->actual_start?->translatedFormat('d M Y') ?? '—' }} – {{ $task->actual_end?->translatedFormat('d M Y') ?? 'berjalan' }}</div>
                        @endif
                    </dd>
                </div>
                <x-ui.detail-item label="Work estimate" class="py-3 sm:flex-col sm:gap-1">
                    {{ $task->estimated_hours !== null ? rtrim(rtrim($task->estimated_hours, '0'), '.').' jam' : '' }}
                    <span class="block text-xs text-zinc-500">Bobot efektif {{ rtrim(rtrim(number_format(TaskWeight::of($task), 2, '.', ''), '0'), '.') }} ({{ TaskWeight::sourceLabel($task) }})</span>
                </x-ui.detail-item>
                <x-ui.detail-item label="Type / Category" class="py-3 sm:flex-col sm:gap-1">{{ collect([$task->type?->name, $task->category?->name])->filter()->join(' · ') }}</x-ui.detail-item>
                <x-ui.detail-item label="Created" class="py-3 sm:flex-col sm:gap-1">{{ $task->created_at?->translatedFormat('d M Y') }}{{ $task->creator ? ' oleh '.$task->creator->name : '' }}</x-ui.detail-item>
            </dl>
        </aside>
    </div>

    {{-- Modals --}}
    @include('partials.workflow.transition-modal')

    <flux:modal name="task-block" class="w-full max-w-md">
        <form wire:submit="block" class="space-y-6">
            <div>
                <flux:heading size="lg">Block Task</flux:heading>
                <flux:text class="mt-1">Jelaskan apa yang menghambat agar tim dapat membantu.</flux:text>
            </div>
            <flux:textarea wire:model="blockReason" label="Reason" badge="Required" rows="3" placeholder="Contoh: menunggu akses API Production." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">Mark as Blocked</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($can['work'])
        <flux:modal name="task-progress" class="w-full max-w-sm">
            <form wire:submit="saveProgress" class="space-y-6">
                <flux:heading size="lg">Update progress</flux:heading>
                <div x-data="{ value: $wire.entangle('progress') }" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:label>Progress</flux:label>
                        <span class="text-2xl font-semibold tabular-nums" x-text="value + '%'"></span>
                    </div>
                    <input type="range" min="0" max="100" step="5" x-model.number="value" class="w-full accent-[var(--color-accent)]" aria-label="Progress" />
                    <flux:input wire:model="progress" type="number" min="0" max="100" class="max-w-32" aria-label="Progress percentage" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save progress</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['schedule'])
        <flux:modal name="task-schedule" class="w-full max-w-md">
            <form wire:submit="saveSchedule" class="space-y-6">
                <div>
                    <flux:heading size="lg">Change Task Schedule</flux:heading>
                    <flux:text class="mt-1">{{ $task->planned_end || $task->planned_start ? 'Rencana awal tidak berubah. Perubahan tercatat beserta alasannya.' : 'Jadwal pertama menjadi rencana awal (baseline) task.' }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="scheduleStart" type="date" label="Start date" />
                    <flux:input wire:model="scheduleEnd" type="date" label="Deadline" />
                </div>
                @if ($task->planned_end || $task->planned_start)
                    <flux:textarea wire:model="scheduleReason" label="Reason" badge="Required" rows="3" />
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Update schedule</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['assign'])
        <flux:modal name="task-pic" class="w-full max-w-md">
            <form wire:submit="savePic" class="space-y-6">
                <div>
                    <flux:heading size="lg">Main PIC</flux:heading>
                    <flux:text class="mt-1">PIC lama tetap tercatat di riwayat assignment.</flux:text>
                </div>
                <div>
                    <livewire:user-picker wire:model="newPicId" label="PIC" :required="true" :only="$this->assignableIds"
                        empty-hint="User belum menjadi anggota project. Tambahkan di tab Team project terlebih dahulu." key="task-pic-picker" />
                    <flux:error name="newPicId" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save PIC</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
