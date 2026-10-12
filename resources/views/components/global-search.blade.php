<?php

use App\Domain\System\Search\GlobalSearch;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $term = '';

    /**
     * @return list<array{group: string, items: list<array{title: string, subtitle: string, url: string}>}>
     */
    #[Computed]
    public function results(): array
    {
        return app(GlobalSearch::class)->search(Auth::user(), $this->term);
    }
}; ?>

<div x-data x-on:keydown.window.prevent.cmd.k="$flux.modal('global-search').show()" x-on:keydown.window.prevent.ctrl.k="$flux.modal('global-search').show()">
    <flux:modal.trigger name="global-search">
        <button type="button"
            class="flex h-9 w-9 items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 text-sm text-zinc-500 transition hover:border-zinc-300 hover:text-zinc-700 sm:w-72 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-zinc-600"
            aria-label="Search">
            <flux:icon.magnifying-glass class="size-4 shrink-0" />
            <span class="hidden flex-1 text-start sm:inline">Search...</span>
            <kbd class="hidden rounded border border-zinc-200 px-1.5 text-[10px] font-medium sm:inline dark:border-zinc-700">⌘K</kbd>
        </button>
    </flux:modal.trigger>

    <flux:modal name="global-search" class="w-full max-w-xl" x-on:close="$wire.set('term', '')">
        <div class="space-y-4">
            <flux:input wire:model.live.debounce.300ms="term" icon="magnifying-glass" placeholder="Cari project, task, ticket, member, department, atau client..." autofocus aria-label="Search" />

            <div class="max-h-96 overflow-y-auto">
                @if (mb_strlen(trim($term)) < 2)
                    <flux:text class="px-1 py-6 text-center">Ketik minimal 2 karakter, misalnya nomor project (PRJ-…), nomor task (TSK-…) atau nama.</flux:text>
                @elseif ($this->results === [])
                    <flux:text class="px-1 py-6 text-center">Tidak ada hasil untuk "{{ $term }}".</flux:text>
                @else
                    @foreach ($this->results as $group)
                        <div wire:key="group-{{ $group['group'] }}" class="mb-3">
                            <div class="px-1 pb-1 text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $group['group'] }}</div>
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate wire:key="{{ $item['url'] }}"
                                   class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-700/50">
                                    <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $item['title'] }}</span>
                                    <span class="truncate text-xs text-zinc-500">{{ $item['subtitle'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </flux:modal>
</div>
