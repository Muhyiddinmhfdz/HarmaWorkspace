<?php

use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Searchable picker for active users, bound with wire:model to an id.
 * Only shows name, username and primary department (user directory data).
 */
new class extends Component {
    #[Modelable]
    public ?int $value = null;

    public string $label = 'User';

    public string $placeholder = 'Cari nama atau username...';

    public ?string $description = null;

    public bool $required = false;

    public string $search = '';

    public bool $open = false;

    /**
     * @var list<int>
     */
    public array $exclude = [];

    /**
     * When set, only these users can be picked (e.g. active project members).
     *
     * @var list<int>|null
     */
    public ?array $only = null;

    public ?string $emptyHint = null;

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return User::query()
            ->active()
            ->whereNotIn('id', $this->exclude)
            ->when($this->only !== null, fn ($query) => $query->whereIn('id', $this->only))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('username', 'like', $like)))
            ->with('primaryMembership.department:id,name')
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'username']);
    }

    #[Computed]
    public function selected(): ?User
    {
        return $this->value ? User::query()->with('primaryMembership.department:id,name')->find($this->value, ['id', 'name', 'username']) : null;
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
                    <flux:avatar size="xs" :name="$this->selected->name" :initials="$this->selected->initials()" />
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800">{{ $this->selected->name }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ '@'.$this->selected->username }}{{ $this->selected->primaryMembership ? ' · '.$this->selected->primaryMembership->department->name : '' }}</div>
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
            @forelse ($this->results as $user)
                <button type="button" wire:key="pick-{{ $user->id }}" wire:click="choose({{ $user->id }})"
                    class="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-zinc-50">
                    <flux:avatar size="xs" :name="$user->name" :initials="$user->initials()" />
                    <div class="min-w-0">
                        <div class="truncate text-sm text-zinc-800">{{ $user->name }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ '@'.$user->username }}{{ $user->primaryMembership ? ' · '.$user->primaryMembership->department->name : '' }}</div>
                    </div>
                </button>
            @empty
                <div class="px-3 py-3 text-sm text-zinc-500">{{ $only !== null && $emptyHint ? $emptyHint : 'Tidak ada user aktif yang cocok.' }}</div>
            @endforelse
        </div>
    @endif
</div>
