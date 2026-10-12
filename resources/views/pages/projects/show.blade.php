<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\ChangeProjectProgressMode;
use App\Domain\Project\Actions\ChangeProjectSchedule;
use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\ChangeProjectVisibility;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Project\Actions\UpdateProjectHealth;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Domain\Project\Support\ProjectLifecycle;
use App\Domain\Project\Support\ProjectProgressCalculator;
use App\Domain\Project\Support\VisibilityGuard;
use App\Enums\ProgressMode;
use App\Enums\ProjectVisibility;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectMilestone;
use App\Models\Status;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    public const array TABS = ['overview' => 'Overview', 'tasks' => 'Tasks', 'milestones' => 'Milestones', 'team' => 'Team', 'activity' => 'Activity'];

    #[Locked]
    public int $projectId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // Modal forms
    public string $visibility = '';

    public string $progressMode = '';

    public int $progress = 0;

    public string $healthId = '';

    public string $healthNote = '';

    public string $scheduleStart = '';

    public string $scheduleEnd = '';

    public string $scheduleReason = '';

    public string $completedOn = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
    }

    #[On('project-updated')]
    public function refreshProject(): void
    {
        unset($this->project, $this->can, $this->transitions, $this->upcomingMilestones, $this->recentActivity, $this->lastScheduleChange, $this->taskSummary, $this->departmentProgress, $this->upcomingTasks, $this->canCompleteNow);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->with([
                'client:id,name',
                'category:id,name',
                'status',
                'priority',
                'health',
                'owner:id,name,username',
                'projectManager:id,name,username',
                'departments' => fn ($query) => $query->orderByDesc('is_lead')->with(['department:id,name', 'leadUser:id,name']),
            ])
            ->findOrFail($this->projectId);
    }

    /**
     * Abilities resolved once per render.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function can(): array
    {
        $user = auth()->user();
        $project = $this->project;
        $editable = ! $project->isArchived();

        return [
            'update' => $editable && $user->can('update', $project),
            'visibility' => $editable && $user->can('changeVisibility', $project),
            'schedule' => $editable && $user->can('manageSchedule', $project),
            'progress' => $editable && $user->can('manageProgress', $project),
            'complete' => $editable && $user->can('complete', $project),
            'archive' => $user->can('archive', $project),
        ];
    }

    /**
     * @return Collection<int, ProjectMilestone>
     */
    #[Computed]
    public function upcomingMilestones(): Collection
    {
        return $this->project->milestones()->whereNull('actual_date')->orderBy('current_due_date')->limit(5)->get();
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->where('subject_type', 'project')
            ->where('subject_id', $this->projectId)
            ->with('causer:id,name')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * Task counters from status flags (never names).
     *
     * @return array{total: int, done: int, in_progress: int, blocked: int, overdue: int, counted: int}
     */
    #[Computed]
    public function taskSummary(): array
    {
        $row = Task::query()
            ->where('tasks.project_id', $this->projectId)
            ->join('statuses', 'statuses.id', '=', 'tasks.status_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(statuses.counts_as_completed) as done')
            ->selectRaw('SUM(statuses.counts_as_started AND NOT statuses.is_terminal AND NOT statuses.is_blocked) as in_progress')
            ->selectRaw('SUM(statuses.is_blocked) as blocked')
            ->selectRaw('SUM(NOT statuses.is_terminal AND tasks.current_end < ?) as overdue', [today()->toDateString()])
            ->selectRaw('SUM(NOT statuses.is_terminal OR statuses.counts_as_completed) as counted')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'done' => (int) ($row->done ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'counted' => (int) ($row->counted ?? 0),
        ];
    }

    /**
     * @return Collection<int, array{department_id: int, name: string, is_lead: bool, progress: int, tasks: int}>
     */
    #[Computed]
    public function departmentProgress(): Collection
    {
        return app(ProjectProgressCalculator::class)->byDepartment($this->project);
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function upcomingTasks(): Collection
    {
        return Task::query()
            ->where('project_id', $this->projectId)
            ->open()
            ->with(['status', 'picAssignment.user:id,name'])
            ->orderByRaw('current_end IS NULL ASC')
            ->orderBy('current_end')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function lastScheduleChange(): ?\App\Models\ScheduleChange
    {
        return $this->project->scheduleChanges()->with('changedBy:id,name')->latest('id')->first();
    }

    /**
     * Lifecycle actions available from the current status (no free status
     * dropdown). Completion is offered separately through CompleteProject.
     *
     * @return list<array{status: Status, label: string, danger: bool}>
     */
    #[Computed]
    public function transitions(): array
    {
        $lifecycle = app(ProjectLifecycle::class);
        $current = $this->project->status;

        if ($this->project->isArchived()) {
            return [];
        }

        return $lifecycle->targets($current)
            ->reject(fn (Status $target): bool => $target->counts_as_completed)
            ->filter(fn (Status $target): bool => $target->is_terminal ? $this->can['complete'] : $this->can['update'])
            ->map(fn (Status $target): array => [
                'status' => $target,
                'label' => $lifecycle->label($current, $target),
                'danger' => $target->is_terminal,
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function canCompleteNow(): bool
    {
        return $this->can['complete']
            && app(ProjectLifecycle::class)->targets($this->project->status)->contains(fn (Status $target): bool => $target->counts_as_completed);
    }

    /**
     * @return Collection<int, ProjectHealthStatus>
     */
    #[Computed]
    public function healthOptions(): Collection
    {
        return ProjectHealthStatus::query()->active()->where('represents_completion', false)->orderBy('level')->get();
    }

    public function moveToStatus(int $statusId, ChangeProjectStatus $changeStatus): void
    {
        $target = Status::query()->findOrFail($statusId);
        $this->authorize($target->is_terminal ? 'complete' : 'update', $this->project);

        $changeStatus->handle($this->project, $target->id, auth()->user());

        $this->notify('success', 'Status project diubah menjadi '.$target->name.'.');
        $this->refreshProject();
    }

    public function openProgressMode(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->progressMode = $this->project->progress_mode->value;
        Flux::modal('project-progress-mode')->show();
    }

    public function saveProgressMode(ChangeProjectProgressMode $changeMode): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['progressMode' => ['required', \Illuminate\Validation\Rule::enum(ProgressMode::class)]]);

        $mode = ProgressMode::from($this->progressMode);
        $changeMode->handle($this->project, $mode, auth()->user());

        Flux::modal('project-progress-mode')->close();
        $this->notify('success', $mode === ProgressMode::TaskWeighted ? 'Progress project kini dihitung otomatis dari Task.' : 'Progress project kini diisi manual.');
        $this->refreshProject();
    }

    public function openVisibility(): void
    {
        abort_unless($this->can['visibility'], 403);
        $this->visibility = $this->project->visibility->value;
        $this->resetValidation();
        Flux::modal('project-visibility')->show();
    }

    public function saveVisibility(ChangeProjectVisibility $changeVisibility): void
    {
        $this->validate(['visibility' => ['required', \Illuminate\Validation\Rule::enum(ProjectVisibility::class)]]);
        $target = ProjectVisibility::from($this->visibility);
        $this->authorize('changeVisibility', [$this->project, $target]);

        $changeVisibility->handle($this->project, $target, auth()->user());

        Flux::modal('project-visibility')->close();
        $this->notify('success', 'Visibility project diubah menjadi '.$target->label().'.');
        $this->refreshProject();
    }

    public function canSetVisibility(ProjectVisibility $visibility): bool
    {
        return app(VisibilityGuard::class)->canSet($visibility, auth()->user());
    }

    public function openProgress(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->progress = $this->project->progress_percentage;
        $this->resetValidation();
        Flux::modal('project-progress')->show();
    }

    public function saveProgress(UpdateProjectProgress $updateProgress): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $updateProgress->handle($this->project, $this->progress, auth()->user());

        Flux::modal('project-progress')->close();
        $this->notify('success', 'Progress project diperbarui menjadi '.$this->progress.'%.');
        $this->refreshProject();
    }

    public function openHealth(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->healthId = (string) $this->project->health_status_id;
        $this->healthNote = '';
        $this->resetValidation();
        Flux::modal('project-health')->show();
    }

    public function saveHealth(UpdateProjectHealth $updateHealth): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['healthId' => ['required', 'integer'], 'healthNote' => ['nullable', 'string', 'max:500']]);

        $updateHealth->handle($this->project, (int) $this->healthId, $this->healthNote ?: null, auth()->user());

        Flux::modal('project-health')->close();
        $this->notify('success', 'Project health diperbarui.');
        $this->refreshProject();
    }

    public function openSchedule(): void
    {
        abort_unless($this->can['schedule'], 403);
        $this->scheduleStart = $this->project->current_start->toDateString();
        $this->scheduleEnd = $this->project->current_end->toDateString();
        $this->scheduleReason = '';
        $this->resetValidation();
        Flux::modal('project-schedule')->show();
    }

    public function saveSchedule(ChangeProjectSchedule $changeSchedule): void
    {
        $this->authorize('manageSchedule', $this->project);
        $this->validate([
            'scheduleStart' => ['required', 'date'],
            'scheduleEnd' => ['required', 'date', 'after_or_equal:scheduleStart'],
            'scheduleReason' => ['required', 'string', 'min:5', 'max:1000'],
        ], attributes: ['scheduleStart' => 'Start date', 'scheduleEnd' => 'New deadline', 'scheduleReason' => 'Reason']);

        $changeSchedule->handle($this->project, $this->scheduleStart, $this->scheduleEnd, $this->scheduleReason, auth()->user());

        Flux::modal('project-schedule')->close();
        $this->notify('success', 'Jadwal project diperbarui.');
        $this->refreshProject();
    }

    public function openComplete(): void
    {
        abort_unless($this->can['complete'], 403);
        $this->completedOn = today()->toDateString();
        $this->resetValidation();
        Flux::modal('project-complete')->show();
    }

    public function complete(CompleteProject $completeProject): void
    {
        $this->authorize('complete', $this->project);
        $this->validate(['completedOn' => ['required', 'date', 'before_or_equal:today']], attributes: ['completedOn' => 'Completion date']);

        $completeProject->handle($this->project, auth()->user(), $this->completedOn);

        Flux::modal('project-complete')->close();
        $this->notify('success', 'Project berhasil diselesaikan.');
        $this->refreshProject();
    }

    public function toggleArchive(ArchiveProject $archiveProject): void
    {
        $this->authorize('archive', $this->project);

        $project = $this->project;
        $project->isArchived() ? $archiveProject->restore($project, auth()->user()) : $archiveProject->archive($project, auth()->user());

        $this->notify('success', $project->isArchived() ? 'Project berhasil diarsipkan.' : 'Project berhasil dipulihkan dari arsip.');
        $this->refreshProject();
    }

    public function render()
    {
        return $this->view()->title($this->project->name);
    }
}; ?>

@php($project = $this->project)
@php($can = $this->can)

<div class="mx-auto w-full max-w-6xl">
    {{-- Header --}}
    <div class="mb-6">
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project->number }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="font-mono text-sm text-zinc-500">{{ $project->number }}</div>
                <flux:heading size="xl" level="1" class="mt-0.5">{{ $project->name }}</flux:heading>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.master-badge :master="$project->status" />
                    <x-ui.master-badge :master="$project->priority" />
                    <x-ui.master-badge :master="$project->health" />
                    @if ($project->isArchived())
                        <flux:badge size="sm" color="zinc" icon="archive-box">Archived</flux:badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($can['update'])
                    <flux:button icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>Edit</flux:button>
                @endif
                @if (in_array(true, $can, true))
                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon:trailing="chevron-down">More</flux:button>
                        <flux:menu>
                            @foreach ($this->transitions as $transition)
                                @if ($transition['danger'])
                                    <flux:menu.item icon="x-circle" variant="danger" wire:key="tr-{{ $transition['status']->id }}"
                                        x-on:click="hwConfirm({ title: 'Batalkan project?', text: 'Project akan berakhir dengan status {{ $transition['status']->name }}. Data dan riwayat tetap tersimpan.', confirmText: 'Batalkan project', cancelText: 'Kembali', danger: true }).then(ok => ok && $wire.moveToStatus({{ $transition['status']->id }}))">
                                        {{ $transition['label'] }}
                                    </flux:menu.item>
                                @else
                                    <flux:menu.item icon="arrow-right-circle" wire:key="tr-{{ $transition['status']->id }}" wire:click="moveToStatus({{ $transition['status']->id }})">{{ $transition['label'] }}</flux:menu.item>
                                @endif
                            @endforeach
                            @if ($this->transitions !== [])
                                <flux:menu.separator />
                            @endif
                            @if ($can['schedule'])
                                <flux:menu.item icon="calendar-days" wire:click="openSchedule">Change schedule</flux:menu.item>
                            @endif
                            @if ($can['progress'])
                                @if ($project->progress_mode === \App\Enums\ProgressMode::Manual)
                                    <flux:menu.item icon="chart-bar" wire:click="openProgress">Update progress</flux:menu.item>
                                @endif
                                <flux:menu.item icon="heart" wire:click="openHealth">Update health</flux:menu.item>
                                <flux:menu.item icon="calculator" wire:click="openProgressMode">Progress calculation</flux:menu.item>
                            @endif
                            @if ($can['visibility'])
                                <flux:menu.item icon="eye" wire:click="openVisibility">Change visibility</flux:menu.item>
                            @endif
                            @if ($this->canCompleteNow)
                                <flux:menu.separator />
                                <flux:menu.item icon="check-badge" wire:click="openComplete">Complete project</flux:menu.item>
                            @endif
                            @if ($can['archive'] && $project->isArchived())
                                <flux:menu.separator />
                                <flux:menu.item icon="arrow-uturn-left"
                                    x-on:click="hwConfirm({ title: 'Pulihkan project dari arsip?', text: 'Project akan kembali tampil di daftar dan dapat diubah lagi.', confirmText: 'Pulihkan' }).then(ok => ok && $wire.toggleArchive())">
                                    Restore from archive
                                </flux:menu.item>
                            @elseif ($can['archive'] && $project->status->is_terminal)
                                <flux:menu.separator />
                                <flux:menu.item icon="archive-box" variant="danger"
                                    x-on:click="hwConfirm({ title: 'Arsipkan Project?', text: 'Project tidak akan tampil di daftar project aktif, tetapi seluruh data dan riwayat tetap tersimpan.', confirmText: 'Arsipkan', cancelText: 'Batal', danger: true }).then(ok => ok && $wire.toggleArchive())">
                                    Archive project
                                </flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <div class="mt-4 max-w-md">
            <div class="mb-1 flex items-center justify-between text-sm">
                <span class="text-zinc-500">Progress</span>
                <span class="font-semibold tabular-nums text-zinc-900">{{ $project->progress_percentage }}% complete</span>
            </div>
            <x-ui.progress-bar :value="$project->progress_percentage" :show-label="false" />
            <div class="mt-1 text-xs text-zinc-500">
                {{ $project->progress_mode === \App\Enums\ProgressMode::TaskWeighted
                    ? ($this->taskSummary['counted'] > 0 ? 'Calculated from '.$this->taskSummary['counted'].' active tasks' : 'Belum ada Task aktif untuk menghitung progress.')
                    : 'Manual' }}
            </div>
        </div>
    </div>

    {{-- Summary --}}
    <dl class="mb-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-zinc-200 bg-zinc-200 lg:grid-cols-5">
        @foreach ([
            'Client' => $project->client->name ?? 'Internal Project',
            'Project Manager' => $project->projectManager->name,
            'Lead Department' => $project->departments->firstWhere('is_lead', true)?->department->name ?? '—',
            'Start date' => $project->current_start->translatedFormat('d M Y'),
        ] as $label => $value)
            <div class="bg-white px-4 py-3" wire:key="summary-{{ $loop->index }}">
                <dt class="text-xs text-zinc-500">{{ $label }}</dt>
                <dd class="mt-1 truncate text-sm font-medium text-zinc-900">{{ $value }}</dd>
            </div>
        @endforeach
        <div class="col-span-2 bg-white px-4 py-3 lg:col-span-1">
            <dt class="text-xs text-zinc-500">Deadline</dt>
            <dd class="mt-1"><x-ui.deadline :date="$project->current_end" :done="$project->completed_at !== null" /></dd>
        </div>
    </dl>

    {{-- Tabs --}}
    <div class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <nav class="flex min-w-max gap-1 border-b border-zinc-200" aria-label="Project sections">
            @foreach ($this::TABS as $key => $label)
                <button type="button" wire:key="tab-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                        'border-accent text-zinc-900' => $tab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800' => $tab !== $key,
                    ])
                    @if ($tab === $key) aria-current="page" @endif>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'overview')
        @php($summary = $this->taskSummary)
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([
                ['Total', $summary['total'], 'text-zinc-900', 'all'],
                ['Done', $summary['done'], 'text-green-700', 'completed'],
                ['In Progress', $summary['in_progress'], 'text-blue-700', 'open'],
                ['Blocked', $summary['blocked'], $summary['blocked'] > 0 ? 'text-red-600' : 'text-zinc-900', 'blocked'],
                ['Overdue', $summary['overdue'], $summary['overdue'] > 0 ? 'text-red-600' : 'text-zinc-900', 'overdue'],
            ] as [$label, $value, $tone, $view])
                <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'tasks', 'view' => $view]) }}" wire:navigate wire:key="ts-{{ $loop->index }}"
                   class="rounded-xl border border-zinc-200 bg-white px-4 py-3 hover:border-zinc-300">
                    <div class="text-xs text-zinc-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums {{ $tone }}">{{ $value }}</div>
                </a>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Upcoming Tasks</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'tasks')">All tasks</flux:button>
                    </div>
                    @forelse ($this->upcomingTasks as $upcoming)
                        <a href="{{ route('tasks.show', $upcoming) }}" wire:navigate wire:key="ut-{{ $upcoming->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $upcoming->title }}</div>
                                <div class="text-xs text-zinc-500">{{ $upcoming->number }} · {{ $upcoming->picAssignment?->user->name ?? 'Tanpa PIC' }}</div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <x-ui.master-badge :master="$upcoming->status" />
                                @if ($upcoming->current_end)
                                    <x-ui.deadline :date="$upcoming->current_end" class="text-end" />
                                @endif
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state icon="check-circle" title="Belum ada Task aktif" description="Pecah pekerjaan Project menjadi Task agar tim dapat mengatur PIC, deadline, dan progress." class="py-8">
                            @if (! $project->isArchived() && ! $project->status->is_terminal && auth()->user()->can('create', [App\Models\Task::class, $project]))
                                <flux:button size="sm" variant="primary" icon="plus" :href="route('tasks.create', ['project' => $project->id])" wire:navigate>New Task</flux:button>
                            @endif
                        </x-ui.empty-state>
                    @endforelse
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">Project Summary</flux:heading>
                    <flux:text class="mt-2 whitespace-pre-line">{{ $project->description ?: 'Belum ada deskripsi.' }}</flux:text>
                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-500">
                        <span>Category: <span class="text-zinc-800">{{ $project->category->name }}</span></span>
                        <span>Owner: <span class="text-zinc-800">{{ $project->owner->name ?? 'Belum ditentukan' }}</span></span>
                        <span>Visibility: <span class="text-zinc-800">{{ $project->visibility->label() }}</span></span>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">Schedule</flux:heading>
                        @if ($can['schedule'])
                            <flux:button size="sm" variant="ghost" icon="calendar-days" wire:click="openSchedule">Change</flux:button>
                        @endif
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-400">Planned (baseline)</div>
                            <div class="mt-1 text-sm text-zinc-800">{{ $project->planned_start->translatedFormat('d M Y') }} – {{ $project->planned_end->translatedFormat('d M Y') }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-400">Current</div>
                            <div class="mt-1 text-sm font-medium text-zinc-900">{{ $project->current_start->translatedFormat('d M Y') }} – {{ $project->current_end->translatedFormat('d M Y') }}</div>
                            @php($slip = (int) $project->planned_end->diffInDays($project->current_end, false))
                            @if ($slip !== 0)
                                <div @class(['text-xs', 'text-red-600' => $slip > 0, 'text-green-600' => $slip < 0])>
                                    {{ $slip > 0 ? 'Mundur '.$slip.' hari dari rencana' : 'Maju '.abs($slip).' hari dari rencana' }}
                                </div>
                            @endif
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-400">Actual</div>
                            <div class="mt-1 text-sm text-zinc-800">
                                {{ $project->actual_start?->translatedFormat('d M Y') ?? 'Belum dimulai' }}
                                @if ($project->actual_end) – {{ $project->actual_end->translatedFormat('d M Y') }} @endif
                            </div>
                        </div>
                    </div>
                    @if ($this->lastScheduleChange)
                        <flux:text size="sm" class="mt-4">
                            Perubahan terakhir oleh {{ $this->lastScheduleChange->changedBy->name ?? 'System' }}, {{ $this->lastScheduleChange->created_at?->diffForHumans() }}: “{{ $this->lastScheduleChange->reason }}”
                        </flux:text>
                    @endif
                </flux:card>

                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Upcoming Milestones</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'milestones')">View all</flux:button>
                    </div>
                    @forelse ($this->upcomingMilestones as $milestone)
                        <div wire:key="up-{{ $milestone->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0">
                            <div class="flex min-w-0 items-center gap-2">
                                <span @class(['size-2 shrink-0 rounded-full', 'bg-red-500' => $milestone->isOverdue(), 'bg-accent' => ! $milestone->isOverdue()])></span>
                                <span class="truncate text-sm text-zinc-800">{{ $milestone->name }}</span>
                                @if ($milestone->is_key_milestone)
                                    <flux:badge size="sm" color="indigo">Key</flux:badge>
                                @endif
                            </div>
                            <x-ui.deadline :date="$milestone->current_due_date" class="shrink-0 text-end" />
                        </div>
                    @empty
                        <x-ui.empty-state icon="flag" title="Belum ada milestone mendatang" description="Milestone membantu tim melihat target utama project." class="py-8" />
                    @endforelse
                </flux:card>
            </div>

            <div class="space-y-6">
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">Project Health</flux:heading>
                        @if ($can['progress'])
                            <flux:button size="sm" variant="ghost" wire:click="openHealth">Update</flux:button>
                        @endif
                    </div>
                    <div class="mt-3"><x-ui.master-badge :master="$project->health" size="lg" /></div>
                    @if ($project->health?->description)
                        <flux:text size="sm" class="mt-2">{{ $project->health->description }}</flux:text>
                    @endif
                </flux:card>

                @if ($summary['total'] > 0)
                    <flux:card>
                        <flux:heading size="lg">Department Progress</flux:heading>
                        <div class="mt-4 space-y-3">
                            @foreach ($this->departmentProgress as $row)
                                <div wire:key="dp-{{ $row['department_id'] }}">
                                    <div class="mb-1 flex items-center justify-between text-sm">
                                        <span class="truncate text-zinc-800">{{ $row['name'] }}</span>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ $row['tasks'] }} tasks</span>
                                    </div>
                                    <x-ui.progress-bar :value="$row['progress']" />
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Departments</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'team')">Team</flux:button>
                    </div>
                    @foreach ($project->departments as $projectDepartment)
                        <div wire:key="dept-{{ $projectDepartment->id }}" class="flex items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 last:border-b-0">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-800">{{ $projectDepartment->department->name }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $projectDepartment->leadUser ? 'Lead: '.$projectDepartment->leadUser->name : 'Lead belum ditentukan' }}</div>
                            </div>
                            <flux:badge size="sm" :color="$projectDepartment->is_lead ? 'indigo' : 'zinc'">{{ $projectDepartment->is_lead ? 'Lead' : 'Supporting' }}</flux:badge>
                        </div>
                    @endforeach
                </flux:card>

                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Recent Activity</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'activity')">All</flux:button>
                    </div>
                    @forelse ($this->recentActivity as $entry)
                        <div wire:key="ra-{{ $entry->id }}" class="border-b border-zinc-100 px-5 py-3 last:border-b-0">
                            <div class="text-sm text-zinc-800"><span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span> {{ \Illuminate\Support\Str::lcfirst($entry->description) }}</div>
                            <flux:text size="sm">{{ $entry->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <x-ui.empty-state icon="clock" title="Belum ada aktivitas" class="py-8" />
                    @endforelse
                </flux:card>
            </div>
        </div>
    @elseif ($tab === 'tasks')
        <livewire:tasks.table mode="project" :project-id="$project->id" wire:key="tasks-{{ $project->id }}" />
    @elseif ($tab === 'milestones')
        <livewire:projects.milestones :project-id="$project->id" wire:key="milestones-{{ $project->id }}" />
    @elseif ($tab === 'team')
        <livewire:projects.team :project-id="$project->id" wire:key="team-{{ $project->id }}" />
    @else
        <livewire:projects.activity :project-id="$project->id" wire:key="activity-{{ $project->id }}" />
    @endif

    {{-- Modals --}}
    @if ($can['progress'])
        <flux:modal name="project-progress-mode" class="w-full max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Progress Calculation</flux:heading>
                    <flux:text class="mt-1">Pilih cara progress project dihitung.</flux:text>
                </div>
                <flux:radio.group wire:model="progressMode" label="Mode">
                    <flux:radio value="manual" label="Manual" description="Project Manager mengisi progress secara manual." />
                    <flux:radio value="task_weighted" label="Based on Tasks" description="Menghitung progress Project secara otomatis dari bobot dan progress Task aktif. Task yang dibatalkan tidak dihitung." />
                </flux:radio.group>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button variant="primary"
                        x-on:click="$wire.progressMode === 'task_weighted' && '{{ $project->progress_mode->value }}' !== 'task_weighted'
                            ? hwConfirm({ title: 'Gunakan progress otomatis?', text: 'Progress Project akan dihitung dari bobot dan progress Task. Progress manual tidak dapat diedit selama mode ini aktif.', confirmText: 'Gunakan otomatis' }).then(ok => ok && $wire.saveProgressMode())
                            : $wire.saveProgressMode()">Save</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    @if ($can['visibility'])
        <flux:modal name="project-visibility" class="w-full max-w-md">
            <form wire:submit="saveVisibility" class="space-y-6">
                <div>
                    <flux:heading size="lg">Project visibility</flux:heading>
                    <flux:text class="mt-1">Visibility hanya mengatur siapa yang dapat melihat project, bukan siapa yang dapat mengubahnya.</flux:text>
                </div>
                <flux:radio.group wire:model="visibility" label="Visibility">
                    @foreach (\App\Enums\ProjectVisibility::cases() as $option)
                        <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description().($this->canSetVisibility($option) ? '' : ' (butuh izin administrator)')" :disabled="! $this->canSetVisibility($option)" wire:key="vis-{{ $option->value }}" />
                    @endforeach
                </flux:radio.group>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save visibility</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['progress'])
        <flux:modal name="project-progress" class="w-full max-w-md">
            <form wire:submit="saveProgress" class="space-y-6">
                <div>
                    <flux:heading size="lg">Update progress</flux:heading>
                    <flux:text class="mt-1">Progress diisi manual sampai modul Task tersedia.</flux:text>
                </div>
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

        <flux:modal name="project-health" class="w-full max-w-md">
            <form wire:submit="saveHealth" class="space-y-6">
                <div>
                    <flux:heading size="lg">Update project health</flux:heading>
                    <flux:text class="mt-1">Penilaian kondisi project oleh Project Manager.</flux:text>
                </div>
                <flux:radio.group wire:model="healthId" label="Health">
                    @foreach ($this->healthOptions as $option)
                        <flux:radio :value="(string) $option->id" :label="$option->name" :description="$option->description" wire:key="h-{{ $option->id }}" />
                    @endforeach
                </flux:radio.group>
                <flux:textarea wire:model="healthNote" label="Note" rows="2" placeholder="Opsional: alasan singkat." />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save health</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['schedule'])
        <flux:modal name="project-schedule" class="w-full max-w-md">
            <form wire:submit="saveSchedule" class="space-y-6">
                <div>
                    <flux:heading size="lg">Change Project Schedule</flux:heading>
                    <flux:text class="mt-1">Rencana awal (baseline) tidak berubah. Perubahan tercatat di riwayat.</flux:text>
                </div>
                <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm">
                    <div class="text-zinc-500">Current deadline</div>
                    <div class="font-medium text-zinc-900">{{ $project->current_end->translatedFormat('d M Y') }}</div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="scheduleStart" type="date" label="Start date" />
                    <flux:input wire:model="scheduleEnd" type="date" label="New deadline" badge="Required" />
                </div>
                <flux:textarea wire:model="scheduleReason" label="Reason" badge="Required" rows="3" placeholder="Contoh: menunggu persetujuan desain dari client." />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Update schedule</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['complete'])
        <flux:modal name="project-complete" class="w-full max-w-md">
            <form wire:submit="complete" class="space-y-6">
                <div>
                    <flux:heading size="lg">Complete project?</flux:heading>
                    <flux:text class="mt-1">Status menjadi selesai, tanggal selesai aktual dicatat, dan progress manual menjadi 100%.</flux:text>
                </div>
                <flux:input wire:model="completedOn" type="date" label="Completion date" badge="Required" :max="today()->toDateString()" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="check-badge">Complete project</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
