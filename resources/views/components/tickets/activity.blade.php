<?php

use App\Domain\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Human-readable ticket history (system events). Comments are shown in the
 * conversation, so comment events are left out here.
 */
new class extends Component {
    private const int PAGE_SIZE = 15;

    #[Locked]
    public int $ticketId;

    public int $limit = self::PAGE_SIZE;

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->authorize('view', Ticket::query()->findOrFail($ticketId));
    }

    #[On('ticket-updated')]
    public function refreshEntries(): void
    {
        unset($this->entries);
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function entries(): Collection
    {
        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->where('subject_type', 'ticket')
            ->where('subject_id', $this->ticketId)
            ->where('event', '!=', 'ticket.comment_added')
            ->with('causer:id,name')
            ->latest('id')
            ->limit($this->limit + 1)
            ->get();
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <flux:heading size="lg" class="mb-3">Activity</flux:heading>
    <ol class="space-y-3">
        @forelse ($this->entries->take($limit) as $entry)
            <li wire:key="tka-{{ $entry->id }}" class="flex gap-3">
                <span class="w-24 shrink-0 pt-0.5 text-xs text-zinc-400">{{ $entry->created_at->translatedFormat('d M H:i') }}</span>
                <div class="min-w-0 text-sm text-zinc-800">
                    <span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span>
                    {{ \Illuminate\Support\Str::lcfirst($entry->description) }}
                    @if ($entry->properties?->get('reason'))
                        <div class="text-zinc-500">Alasan: “{{ $entry->properties->get('reason') }}”</div>
                    @endif
                    @if ($entry->properties?->get('note'))
                        <div class="text-zinc-500">Catatan: “{{ $entry->properties->get('note') }}”</div>
                    @endif
                </div>
            </li>
        @empty
            <li class="text-sm text-zinc-500">Belum ada aktivitas.</li>
        @endforelse
    </ol>
    @if ($this->entries->count() > $limit)
        <div class="mt-3 text-center">
            <flux:button size="sm" variant="ghost" wire:click="loadMore">Load more</flux:button>
        </div>
    @endif
</div>
