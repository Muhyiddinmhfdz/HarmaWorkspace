<?php

use App\Domain\Project\Actions\ManageProjectMilestones;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $projectId;

    public string $filter = 'all';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $completingId = null;

    public string $name = '';

    public string $description = '';

    public string $due_date = '';

    public ?int $owner_id = null;

    public bool $is_key_milestone = false;

    public string $reason = '';

    public string $actual_date = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->authorize('view', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return ! $this->project->isArchived() && auth()->user()->can('manageMilestones', $this->project);
    }

    /**
     * @return Collection<int, ProjectMilestone>
     */
    #[Computed]
    public function milestones(): Collection
    {
        return $this->project->milestones()
            ->with('owner:id,name')
            ->when($this->filter === 'upcoming', fn ($query) => $query->whereNull('actual_date'))
            ->when($this->filter === 'completed', fn ($query) => $query->whereNotNull('actual_date'))
            ->orderBy('current_due_date')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function nextMilestoneId(): ?int
    {
        return $this->project->milestones()->whereNull('actual_date')->orderBy('current_due_date')->value('id');
    }

    private function milestone(int $id): ProjectMilestone
    {
        return $this->project->milestones()->findOrFail($id);
    }

    public function create(): void
    {
        $this->authorize('manageMilestones', $this->project);

        $this->resetForm();
        $this->due_date = min($this->project->current_end, today()->addWeeks(2))->toDateString();
        Flux::modal('milestone-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('manageMilestones', $this->project);

        $milestone = $this->milestone($id);
        $this->resetForm();
        $this->editingId = $milestone->id;
        $this->name = $milestone->name;
        $this->description = (string) $milestone->description;
        $this->due_date = $milestone->current_due_date->toDateString();
        $this->owner_id = $milestone->owner_id;
        $this->is_key_milestone = $milestone->is_key_milestone;
        Flux::modal('milestone-form')->show();
    }

    public function save(ManageProjectMilestones $milestones): void
    {
        $this->authorize('manageMilestones', $this->project);

        $editing = $this->editingId ? $this->milestone($this->editingId) : null;
        $dueChanged = $editing !== null && $editing->current_due_date->toDateString() !== $this->due_date;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['required', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_key_milestone' => ['boolean'],
            'reason' => [$dueChanged ? 'required' : 'nullable', 'string', 'max:1000'],
        ], attributes: ['due_date' => 'Target date', 'reason' => 'Reason']);

        if ($editing) {
            $milestones->update($editing, $validated, auth()->user());
            $this->notify('success', 'Milestone berhasil diperbarui.');
        } else {
            $milestones->create($this->project, $validated, auth()->user());
            $this->notify('success', 'Milestone berhasil ditambahkan.');
        }

        Flux::modal('milestone-form')->close();
        $this->resetForm();
        $this->dispatch('project-updated');
    }

    public function confirmComplete(int $id): void
    {
        $this->authorize('manageMilestones', $this->project);

        $this->completingId = $this->milestone($id)->id;
        $this->actual_date = today()->toDateString();
        $this->resetValidation();
        Flux::modal('milestone-complete')->show();
    }

    public function complete(ManageProjectMilestones $milestones): void
    {
        $this->authorize('manageMilestones', $this->project);
        $this->validate(['actual_date' => ['required', 'date', 'before_or_equal:today']], attributes: ['actual_date' => 'Date achieved']);

        $milestones->complete($this->milestone((int) $this->completingId), $this->actual_date, auth()->user());

        Flux::modal('milestone-complete')->close();
        $this->notify('success', 'Milestone ditandai tercapai.');
        $this->completingId = null;
        $this->dispatch('project-updated');
    }

    public function reopen(int $id, ManageProjectMilestones $milestones): void
    {
        $this->authorize('manageMilestones', $this->project);

        $milestones->reopen($this->milestone($id), auth()->user());

        $this->notify('success', 'Milestone dibuka kembali.');
        $this->dispatch('project-updated');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'due_date', 'owner_id', 'is_key_milestone', 'reason');
        $this->resetValidation();
    }
}; ?>

<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:radio.group wire:model.live="filter" variant="segmented" size="sm" aria-label="Filter milestones">
            <flux:radio value="all" label="All" />
            <flux:radio value="upcoming" label="Upcoming" />
            <flux:radio value="completed" label="Completed" />
        </flux:radio.group>
        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add Milestone</flux:button>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white">
        @if ($this->milestones->isEmpty())
            <x-ui.empty-state icon="flag" title="{{ $filter === 'all' ? 'Belum ada Milestone.' : 'Tidak ada milestone di filter ini.' }}" description="Milestone membantu tim melihat target utama Project.">
                @if ($this->canManage && $filter === 'all')
                    <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add Milestone</flux:button>
                @endif
            </x-ui.empty-state>
        @else
            <ol class="relative px-5 py-4">
                @foreach ($this->milestones as $milestone)
                    @php($isNext = $milestone->id === $this->nextMilestoneId)
                    <li wire:key="milestone-{{ $milestone->id }}" class="relative flex gap-4 pb-6 last:pb-0">
                        @unless ($loop->last)
                            <span class="absolute start-[11px] top-7 h-[calc(100%-1.25rem)] w-px bg-zinc-200" aria-hidden="true"></span>
                        @endunless

                        <span @class([
                            'relative z-1 mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                            'border-green-500 bg-green-500 text-white' => $milestone->isCompleted(),
                            'border-red-500 bg-white' => ! $milestone->isCompleted() && $milestone->isOverdue(),
                            'border-accent bg-accent' => ! $milestone->isCompleted() && ! $milestone->isOverdue() && $isNext,
                            'border-zinc-300 bg-white' => ! $milestone->isCompleted() && ! $milestone->isOverdue() && ! $isNext,
                        ])>
                            @if ($milestone->isCompleted())
                                <flux:icon.check class="size-3.5" variant="micro" />
                            @endif
                            <span class="sr-only">{{ $milestone->isCompleted() ? 'Completed' : ($milestone->isOverdue() ? 'Overdue' : 'Upcoming') }}</span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span @class(['font-medium', 'text-zinc-500 line-through decoration-zinc-300' => $milestone->isCompleted(), 'text-zinc-900' => ! $milestone->isCompleted()])>{{ $milestone->name }}</span>
                                        @if ($milestone->is_key_milestone)
                                            <flux:badge size="sm" color="indigo">Key</flux:badge>
                                        @endif
                                        @if ($isNext && ! $milestone->isOverdue())
                                            <flux:badge size="sm" color="sky">Next</flux:badge>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 text-sm">
                                        @if ($milestone->isCompleted())
                                            <span class="text-green-700">Tercapai {{ $milestone->actual_date->translatedFormat('d M Y') }}</span>
                                        @else
                                            @php($deadline = \App\Domain\Project\Support\Deadline::for($milestone->current_due_date))
                                            <span class="text-zinc-700">Target {{ $milestone->current_due_date->translatedFormat('d M Y') }}</span>
                                            <span @class(['ms-1', 'text-red-600' => $deadline->tone === 'red', 'text-amber-600' => $deadline->tone === 'amber', 'text-zinc-500' => $deadline->tone === 'zinc'])>· {{ $deadline->label }}</span>
                                        @endif
                                    </div>
                                    @if (! $milestone->planned_date->equalTo($milestone->current_due_date))
                                        <div class="text-xs text-zinc-500">Rencana awal {{ $milestone->planned_date->translatedFormat('d M Y') }}</div>
                                    @endif
                                    @if ($milestone->owner || $milestone->description)
                                        <div class="mt-1 text-sm text-zinc-500">
                                            @if ($milestone->owner) Owner: {{ $milestone->owner->name }} @endif
                                            @if ($milestone->description) <div class="whitespace-pre-line">{{ $milestone->description }}</div> @endif
                                        </div>
                                    @endif
                                </div>

                                @if ($this->canManage)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions for {{ $milestone->name }}" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $milestone->id }})">Edit</flux:menu.item>
                                            @if ($milestone->isCompleted())
                                                <flux:menu.item icon="arrow-uturn-left" wire:click="reopen({{ $milestone->id }})">Reopen</flux:menu.item>
                                            @else
                                                <flux:menu.item icon="check-circle" wire:click="confirmComplete({{ $milestone->id }})">Mark as achieved</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($this->canManage)
        <flux:modal name="milestone-form" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-6">
                <flux:heading size="lg">{{ $editingId ? 'Edit Milestone' : 'Add Milestone' }}</flux:heading>
                <flux:input wire:model="name" label="Milestone name" badge="Required" maxlength="150" placeholder="Contoh: UI Design Completed" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live="due_date" type="date" label="Target date" badge="Required" />
                    <div class="pt-7">
                        <flux:switch wire:model="is_key_milestone" label="Key milestone" />
                    </div>
                </div>
                @if ($editingId)
                    <flux:textarea wire:model="reason" label="Reason for date change" rows="2" description="Wajib diisi jika target date diubah." />
                @endif
                <livewire:user-picker wire:model="owner_id" label="Owner" description="Penanggung jawab milestone (opsional)." :key="'milestone-owner-'.($editingId ?? 'new')" />
                <flux:textarea wire:model="description" label="Description" rows="2" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="milestone-complete" class="w-full max-w-sm">
            <form wire:submit="complete" class="space-y-6">
                <div>
                    <flux:heading size="lg">Mark milestone as achieved</flux:heading>
                    <flux:text class="mt-1">Catat tanggal milestone benar-benar tercapai.</flux:text>
                </div>
                <flux:input wire:model="actual_date" type="date" label="Date achieved" badge="Required" :max="today()->toDateString()" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
