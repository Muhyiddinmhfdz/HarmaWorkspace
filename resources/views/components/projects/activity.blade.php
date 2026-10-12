<?php

use App\Domain\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    private const int PAGE_SIZE = 20;

    #[Locked]
    public int $projectId;

    public int $limit = self::PAGE_SIZE;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->authorize('view', Project::query()->findOrFail($projectId));
    }

    /**
     * Friendly project timeline, loaded in pages ("Load more").
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function entries(): Collection
    {
        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('subject_type', 'project')->where('subject_id', $this->projectId))
                ->orWhere(fn ($query) => $query
                    ->where('subject_type', 'task')
                    ->whereIn('event', \App\Domain\Task\Support\TaskAudit::PROJECT_EVENTS)
                    ->where('properties->project_id', $this->projectId))
                ->orWhere(fn ($query) => $query
                    ->where('subject_type', 'ticket')
                    ->whereIn('event', \App\Domain\Ticket\Support\TicketAudit::PROJECT_EVENTS)
                    ->where('properties->project_id', $this->projectId)
                    ->whereIn('subject_id', \App\Models\Ticket::query()->visibleTo(auth()->user())->select('tickets.id'))))
            ->with('causer:id,name')
            ->latest('id')
            ->limit($this->limit + 1)
            ->get();
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function dayLabel(\Carbon\CarbonInterface $date): string
    {
        return match (true) {
            $date->isToday() => 'Today',
            $date->isYesterday() => 'Yesterday',
            default => $date->translatedFormat('l, d M Y'),
        };
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white px-5 py-4">
    @php($visible = $this->entries->take($limit))

    @forelse ($visible->groupBy(fn ($entry) => $entry->created_at->toDateString()) as $day => $dayEntries)
        <section wire:key="day-{{ $day }}" class="mb-6 last:mb-0">
            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $this->dayLabel($dayEntries->first()->created_at) }}</h3>
            <ol class="space-y-4">
                @foreach ($dayEntries as $entry)
                    <li wire:key="entry-{{ $entry->id }}" class="flex gap-3">
                        <span class="w-12 shrink-0 pt-0.5 text-xs tabular-nums text-zinc-400">{{ $entry->created_at->format('H:i') }}</span>
                        <div class="min-w-0">
                            <div class="text-sm text-zinc-800">
                                <span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span>
                                {{ \Illuminate\Support\Str::lcfirst($entry->description) }}
                            </div>
                            @if ($entry->properties?->get('reason'))
                                <div class="mt-0.5 text-sm text-zinc-500">Alasan: “{{ $entry->properties->get('reason') }}”</div>
                            @elseif ($entry->properties?->get('note'))
                                <div class="mt-0.5 text-sm text-zinc-500">Catatan: “{{ $entry->properties->get('note') }}”</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @empty
        <x-ui.empty-state icon="clock" title="Belum ada aktivitas" description="Perubahan project, tim, jadwal, milestone, serta task dan ticket penting akan tercatat di sini." />
    @endforelse

    @if ($this->entries->count() > $limit)
        <div class="mt-4 border-t border-zinc-100 pt-4 text-center">
            <flux:button size="sm" variant="ghost" wire:click="loadMore">
                <span wire:loading.remove wire:target="loadMore">Load more</span>
                <span wire:loading wire:target="loadMore">Loading...</span>
            </flux:button>
        </div>
    @endif
</div>
