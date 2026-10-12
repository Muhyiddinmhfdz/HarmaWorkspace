<?php

use App\Domain\Task\Actions\ManageTaskSubtasks;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Task;
use App\Models\TaskSubtask;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $taskId;

    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    public string $description = '';

    public ?int $pic_user_id = null;

    public string $due_date = '';

    public function mount(int $taskId): void
    {
        $this->taskId = $taskId;
        $this->authorize('view', $this->task);
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()->with('project')->findOrFail($this->taskId);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->can('participate', $this->task);
    }

    /**
     * @return Collection<int, TaskSubtask>
     */
    #[Computed]
    public function subtasks(): Collection
    {
        return $this->task->subtasks()->with('pic:id,name')->withCount(['checklistItems', 'checklistItems as completed_checklist_count' => fn ($query) => $query->where('is_completed', true)])->get();
    }

    /**
     * @return list<int>|null
     */
    #[Computed]
    public function assignableIds(): ?array
    {
        $project = $this->task->project;

        return $project === null ? null : array_values(array_unique([$project->project_manager_id, ...array_map(intval(...), $project->members()->pluck('user_id')->all())]));
    }

    private function subtask(int $id): TaskSubtask
    {
        return $this->task->subtasks()->findOrFail($id);
    }

    public function create(): void
    {
        $this->authorize('participate', $this->task);
        $this->reset('editingId', 'title', 'description', 'pic_user_id', 'due_date');
        $this->resetValidation();
        Flux::modal('subtask-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('participate', $this->task);
        $subtask = $this->subtask($id);
        $this->editingId = $subtask->id;
        $this->title = $subtask->title;
        $this->description = (string) $subtask->description;
        $this->pic_user_id = $subtask->pic_user_id;
        $this->due_date = (string) $subtask->due_date?->toDateString();
        $this->resetValidation();
        Flux::modal('subtask-form')->show();
    }

    public function save(ManageTaskSubtasks $subtasks): void
    {
        $this->authorize('participate', $this->task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'pic_user_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
        ], attributes: ['title' => 'Subtask title', 'due_date' => 'Due date']);

        if ($this->editingId) {
            $subtasks->update($this->subtask($this->editingId), $validated, auth()->user());
            $this->notify('success', 'Subtask diperbarui.');
        } else {
            $subtasks->create($this->task, $validated, auth()->user());
            $this->notify('success', 'Subtask ditambahkan.');
        }

        Flux::modal('subtask-form')->close();
        unset($this->subtasks);
        $this->dispatch('task-updated');
    }

    public function toggle(int $id, ManageTaskSubtasks $subtasks): void
    {
        $this->authorize('participate', $this->task);

        $subtasks->toggle($this->subtask($id), auth()->user());

        unset($this->subtasks);
        $this->dispatch('task-updated');
    }

    public function remove(int $id, ManageTaskSubtasks $subtasks): void
    {
        $this->authorize('participate', $this->task);

        $subtasks->remove($this->subtask($id), auth()->user());

        Flux::modal('subtask-form')->close();
        $this->notify('success', 'Subtask dihapus.');
        unset($this->subtasks);
        $this->dispatch('task-updated');
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="mb-3 flex items-center justify-between">
        <flux:heading size="lg">
            Subtasks
            @if ($this->subtasks->isNotEmpty())
                <span class="ms-1 text-sm font-normal text-zinc-500">{{ $this->subtasks->where('is_completed', true)->count() }} / {{ $this->subtasks->count() }} completed</span>
            @endif
        </flux:heading>
        @if ($this->canEdit)
            <flux:button size="sm" icon="plus" wire:click="create">Add Subtask</flux:button>
        @endif
    </div>

    @if ($this->subtasks->isEmpty())
        <flux:text>Belum ada Subtask. Gunakan Subtask untuk memecah pekerjaan besar menjadi bagian yang lebih kecil.</flux:text>
    @else
        <ul class="divide-y divide-zinc-100">
            @foreach ($this->subtasks as $subtask)
                <li wire:key="st-{{ $subtask->id }}" class="flex items-start gap-3 py-2">
                    <input type="checkbox" @checked($subtask->is_completed) @disabled(! $this->canEdit) wire:click="toggle({{ $subtask->id }})"
                        class="mt-1 size-4 shrink-0 rounded border-zinc-300 accent-[var(--color-accent)]" aria-label="Toggle {{ $subtask->title }}" />
                    <button type="button" class="min-w-0 flex-1 text-start" @if ($this->canEdit) wire:click="edit({{ $subtask->id }})" @endif>
                        <div @class(['text-sm', 'text-zinc-400 line-through' => $subtask->is_completed, 'text-zinc-800' => ! $subtask->is_completed])>{{ $subtask->title }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ collect([
                                $subtask->pic?->name,
                                $subtask->due_date ? 'Due '.$subtask->due_date->translatedFormat('d M') : null,
                                $subtask->checklist_items_count ? $subtask->completed_checklist_count.'/'.$subtask->checklist_items_count.' checklist' : null,
                            ])->filter()->join(' · ') }}
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->canEdit)
        <flux:modal name="subtask-form" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">{{ $editingId ? 'Edit Subtask' : 'Add Subtask' }}</flux:heading>
                <flux:input wire:model="title" label="Subtask title" badge="Required" maxlength="200" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <livewire:user-picker wire:model="pic_user_id" label="PIC" :only="$this->assignableIds" :key="'subtask-pic-'.($editingId ?? 'new')" />
                    <flux:input wire:model="due_date" type="date" label="Due date" />
                </div>
                <flux:textarea wire:model="description" label="Description" rows="2" />
                @if ($editingId)
                    <livewire:tasks.checklist owner-type="subtask" :owner-id="$editingId" :compact="true" :key="'subtask-checklist-'.$editingId" />
                @endif
                <div class="flex items-center justify-between gap-2">
                    @if ($editingId)
                        <flux:button variant="ghost" icon="trash" class="text-red-600!"
                            x-on:click="hwConfirm({ title: 'Hapus subtask?', text: 'Subtask dan checklist di dalamnya akan dihapus. Catatan penghapusan tetap ada di activity task.', confirmText: 'Hapus', danger: true }).then(ok => ok && $wire.remove({{ $editingId }}))">Delete</flux:button>
                    @else
                        <span></span>
                    @endif
                    <div class="flex gap-2">
                        <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">Save</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
