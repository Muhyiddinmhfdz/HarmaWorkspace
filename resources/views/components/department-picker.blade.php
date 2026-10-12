<?php

use App\Models\Department;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Searchable picker for active departments, bound with wire:model to an id.
 */
new class extends Component {
    #[Modelable]
    public ?int $value = null;

    public string $label = 'Department';

    public string $placeholder = 'Cari department...';

    public ?string $description = null;

    public bool $required = false;

    public string $search = '';

    public bool $open = false;

    /**
     * @var list<int>
     */
    public array $exclude = [];

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return Department::query()
            ->active()
            ->whereNotIn('id', $this->exclude)
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function selected(): ?Department
    {
        return $this->value ? Department::query()->find($this->value, ['id', 'code', 'name']) : null;
    }

    public function choose(int $id): void
    {
        $this->value = $id;
        $this->search = '';
        $this->open = false;
    }

    public function clear(): void
    {
        $this->value = null;
    }
}; ?>

<div class="relative" x-data x-on:click.outside="$wire.open = false">
    <flux:field>
        <flux:label :badge="$required ? 'Required' : null">{{ $label }}</flux:label>
        @if ($description)
            <flux:description>{{ $description }}</flux:description>
        @endif

        @if ($this->selected)
            <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2">
                <div class="flex min-w-0 items-center gap-3">
                    <flux:icon.building-office-2 class="size-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800">{{ $this->selected->name }}</div>
                        <div class="truncate font-mono text-xs text-zinc-500">{{ $this->selected->code }}</div>
                    </div>
                </div>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clear" aria-label="Clear selection" />
            </div>
        @else
            <flux:input wire:model.live.debounce.250ms="search" wire:focus="$set('open', true)" icon="magnifying-glass" :placeholder="$placeholder" autocomplete="off" />
        @endif
    </flux:field>

    @if ($open && ! $this->selected)
        <div class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
            @forelse ($this->results as $department)
                <button type="button" wire:key="dpick-{{ $department->id }}" wire:click="choose({{ $department->id }})"
                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-zinc-50">
                    <span class="truncate text-sm text-zinc-800">{{ $department->name }}</span>
                    <span class="font-mono text-xs text-zinc-400">{{ $department->code }}</span>
                </button>
            @empty
                <div class="px-3 py-3 text-sm text-zinc-500">Tidak ada department aktif yang cocok.</div>
            @endforelse
        </div>
    @endif
</div>
