<?php

use App\Models\Ticket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Searchable picker for tickets the user can see (by number or title).
 */
new class extends Component {
    #[Modelable]
    public ?int $value = null;

    public string $label = 'Report';

    public bool $required = false;

    public string $search = '';

    public bool $open = false;

    /**
     * @var list<int>
     */
    public array $exclude = [];

    /**
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return Ticket::query()
            ->visibleTo(auth()->user())
            ->whereNotIn('id', $this->exclude)
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('number', 'like', $like)->orWhere('title', 'like', $like)))
            ->latest('id')
            ->limit(8)
            ->get(['id', 'number', 'title']);
    }

    #[Computed]
    public function selected(): ?Ticket
    {
        return $this->value ? Ticket::query()->visibleTo(auth()->user())->find($this->value, ['id', 'number', 'title']) : null;
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
        @if ($this->selected)
            <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2">
                <div class="min-w-0">
                    <div class="font-mono text-xs text-zinc-500">{{ $this->selected->number }}</div>
                    <div class="truncate text-sm font-medium text-zinc-800">{{ $this->selected->title }}</div>
                </div>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clear" aria-label="Clear selection" />
            </div>
        @else
            <flux:input wire:model.live.debounce.250ms="search" wire:focus="$set('open', true)" icon="magnifying-glass" placeholder="Cari nomor atau judul report..." autocomplete="off" />
        @endif
    </flux:field>

    @if ($open && ! $this->selected)
        <div class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
            @forelse ($this->results as $result)
                <button type="button" wire:key="tpick-{{ $result->id }}" wire:click="choose({{ $result->id }})" class="block w-full px-3 py-2 text-start hover:bg-zinc-50">
                    <div class="font-mono text-xs text-zinc-500">{{ $result->number }}</div>
                    <div class="truncate text-sm text-zinc-800">{{ $result->title }}</div>
                </button>
            @empty
                <div class="px-3 py-3 text-sm text-zinc-500">Tidak ada report yang cocok.</div>
            @endforelse
        </div>
    @endif
</div>
