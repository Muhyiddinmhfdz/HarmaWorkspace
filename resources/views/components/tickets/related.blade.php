<?php

use App\Models\Task;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Related reports (tickets) of a task: only tickets the user may see. Ticket
 * and task lifecycles stay independent (ADR-059).
 */
new class extends Component {
    #[Locked]
    public int $taskId;

    public function mount(int $taskId): void
    {
        $this->taskId = $taskId;
        $this->authorize('view', Task::query()->findOrFail($taskId));
    }

    /**
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function tickets(): Collection
    {
        if (! auth()->user()->can('viewAny', Ticket::class)) {
            return collect();
        }

        return Ticket::query()
            ->visibleTo(auth()->user())
            ->where('task_id', $this->taskId)
            ->with(['status', 'priority', 'type:id,name,color', 'currentDepartment:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function canCreate(): bool
    {
        $task = Task::query()->with('project.status')->findOrFail($this->taskId);

        return auth()->user()->can('create', Ticket::class)
            && ($task->project === null || (! $task->project->isArchived() && ! $task->project->status->is_terminal));
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="mb-3 flex items-center justify-between gap-3">
        <flux:heading size="lg">Related Reports</flux:heading>
        @if ($this->canCreate)
            <flux:button size="sm" variant="ghost" icon="plus" :href="route('tickets.create', ['task' => $taskId])" wire:navigate>New Report</flux:button>
        @endif
    </div>
    <ul class="divide-y divide-zinc-100">
        @forelse ($this->tickets as $ticket)
            <li wire:key="rt-{{ $ticket->id }}" class="py-2.5">
                <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="flex min-w-0 items-start justify-between gap-3">
                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$ticket->type->color ?? 'zinc'" inset="top bottom" class="uppercase">{{ $ticket->type->name }}</flux:badge>
                            <span class="truncate font-mono text-xs text-zinc-500">{{ $ticket->number }} · {{ $ticket->currentDepartment->name }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-sm font-medium text-zinc-800 hover:underline">{{ $ticket->title }}</span>
                    </span>
                    <x-ui.master-badge :master="$ticket->status" class="shrink-0" />
                </a>
            </li>
        @empty
            <li class="py-2 text-sm text-zinc-500">Belum ada report untuk task ini. Catat bug, kendala, atau request terkait task ini sebagai report.</li>
        @endforelse
    </ul>
</div>
