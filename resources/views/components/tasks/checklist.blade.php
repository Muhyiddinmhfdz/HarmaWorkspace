<?php

use App\Domain\Task\Actions\ManageChecklist;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ChecklistItem;
use App\Models\Task;
use App\Models\TaskSubtask;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Checklist of a task or a subtask (owner-type = task|subtask).
 */
new class extends Component {
    use WithFeedback;

    #[Locked]
    public string $ownerType = 'task';

    #[Locked]
    public int $ownerId;

    public bool $compact = false;

    public string $newItem = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editingText = '';

    public function mount(string $ownerType, int $ownerId, bool $compact = false): void
    {
        $this->ownerType = $ownerType === 'subtask' ? 'subtask' : 'task';
        $this->ownerId = $ownerId;
        $this->compact = $compact;
        $this->authorize('view', $this->task);
    }

    #[Computed]
    public function owner(): Task|TaskSubtask
    {
        return $this->ownerType === 'subtask'
            ? TaskSubtask::query()->findOrFail($this->ownerId)
            : Task::query()->findOrFail($this->ownerId);
    }

    #[Computed]
    public function task(): Task
    {
        $owner = $this->owner;

        return $owner instanceof Task ? $owner : $owner->task()->firstOrFail();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->can('participate', $this->task);
    }

    /**
     * @return Collection<int, ChecklistItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->owner->checklistItems()->with('completedBy:id,name')->get();
    }

    private function item(int $id): ChecklistItem
    {
        return $this->owner->checklistItems()->findOrFail($id);
    }

    public function add(ManageChecklist $checklist): void
    {
        $this->authorize('participate', $this->task);
        $this->validate(['newItem' => ['required', 'string', 'max:500']], attributes: ['newItem' => 'Checklist item']);

        $checklist->add($this->owner, $this->newItem);

        $this->newItem = '';
        unset($this->items);
    }

    public function toggle(int $id, ManageChecklist $checklist): void
    {
        $this->authorize('participate', $this->task);

        $checklist->toggle($this->item($id), auth()->user());

        unset($this->items);
    }

    public function edit(int $id): void
    {
        $this->authorize('participate', $this->task);
        $item = $this->item($id);
        $this->editingId = $item->id;
        $this->editingText = $item->text;
    }

    public function saveEdit(ManageChecklist $checklist): void
    {
        $this->authorize('participate', $this->task);
        $this->validate(['editingText' => ['required', 'string', 'max:500']], attributes: ['editingText' => 'Checklist item']);

        $checklist->update($this->item((int) $this->editingId), $this->editingText);

        $this->reset('editingId', 'editingText');
        unset($this->items);
    }

    public function remove(int $id, ManageChecklist $checklist): void
    {
        $this->authorize('participate', $this->task);

        $checklist->remove($this->item($id));

        unset($this->items);
    }
}; ?>

<div @class(['rounded-xl border border-zinc-200 bg-white p-5' => ! $compact])>
    <div class="mb-3 flex items-center justify-between">
        <flux:heading :size="$compact ? 'base' : 'lg'">
            Checklist
            @if ($this->items->isNotEmpty())
                <span class="ms-1 text-sm font-normal text-zinc-500">{{ $this->items->where('is_completed', true)->count() }} / {{ $this->items->count() }}</span>
            @endif
        </flux:heading>
    </div>

    @if ($this->items->isEmpty() && ! $compact)
        <flux:text class="mb-3">Belum ada Checklist. Tambahkan langkah kecil yang perlu diselesaikan dalam Task ini.</flux:text>
    @endif

    <ul class="space-y-1">
        @foreach ($this->items as $item)
            <li wire:key="cl-{{ $item->id }}" class="group flex items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-50">
                <input type="checkbox" @checked($item->is_completed) @disabled(! $this->canEdit)
                    wire:click="toggle({{ $item->id }})"
                    class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 accent-[var(--color-accent)]"
                    aria-label="Toggle {{ $item->text }}" />
                @if ($editingId === $item->id)
                    <form wire:submit="saveEdit" class="flex flex-1 gap-2">
                        <flux:input wire:model="editingText" size="sm" class="flex-1" autofocus />
                        <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="$set('editingId', null)">Cancel</flux:button>
                    </form>
                @else
                    <div class="min-w-0 flex-1">
                        <div @class(['text-sm', 'text-zinc-400 line-through' => $item->is_completed, 'text-zinc-800' => ! $item->is_completed])>{{ $item->text }}</div>
                        @if ($item->is_completed && $item->completedBy)
                            <div class="text-xs text-zinc-400">{{ $item->completedBy->name }} · {{ $item->completed_at?->translatedFormat('d M H:i') }}</div>
                        @endif
                    </div>
                    @if ($this->canEdit)
                        <div class="flex shrink-0 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" aria-label="Edit item" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove({{ $item->id }})" aria-label="Delete item" />
                        </div>
                    @endif
                @endif
            </li>
        @endforeach
    </ul>

    @if ($this->canEdit)
        <form wire:submit="add" class="mt-3 flex gap-2">
            <flux:input wire:model="newItem" size="sm" placeholder="Tambah item checklist..." class="flex-1" aria-label="New checklist item" />
            <flux:button type="submit" size="sm" icon="plus">Add item</flux:button>
        </form>
        <flux:error name="newItem" />
    @endif
</div>
