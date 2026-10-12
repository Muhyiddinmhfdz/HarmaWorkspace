<?php

namespace App\Domain\System\Dashboard;

use App\Domain\Ticket\Support\TicketQueue;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;

/**
 * "What needs my attention now?" for the dashboard: blocked and overdue tasks
 * assigned to the user, reports waiting for the user, pending approvals and,
 * for department leaders, reports without a PIC.
 *
 * Plain queries over existing scopes and status flags (no persisted state,
 * no names). Every source applies the module's visibility scope.
 */
class NeedsAttention
{
    private const int PER_SOURCE = 3;

    public function __construct(private TicketQueue $queue) {}

    /**
     * @param  int  $pendingApprovals  already counted by the caller (avoids a second scan)
     * @param  array<string, int>|null  $leaderCounts  inbox counters of led departments, if any
     * @return list<array{kind: string, label: string, title: string, meta: string|null, url: string, tone: string}>
     */
    public function for(User $user, int $pendingApprovals = 0, ?array $leaderCounts = null, int $limit = 8): array
    {
        $items = [];

        if ($user->can('viewAny', Task::class)) {
            array_push($items, ...$this->tasks($user));
        }

        if ($user->can('viewAny', Ticket::class)) {
            array_push($items, ...$this->reports($user));
        }

        if ($pendingApprovals > 0) {
            $items[] = [
                'kind' => 'approval',
                'label' => 'Approval',
                'title' => $pendingApprovals.' permintaan menunggu keputusan Anda',
                'meta' => null,
                'url' => route('approvals.mine'),
                'tone' => 'amber',
            ];
        }

        if (($leaderCounts['unassigned'] ?? 0) > 0) {
            $items[] = [
                'kind' => 'inbox',
                'label' => 'Department Inbox',
                'title' => $leaderCounts['unassigned'].' report belum memiliki PIC',
                'meta' => null,
                'url' => route('tickets.inbox', ['view' => 'unassigned']),
                'tone' => 'amber',
            ];
        }

        if (($leaderCounts['new'] ?? 0) > 0) {
            $items[] = [
                'kind' => 'inbox',
                'label' => 'Department Inbox',
                'title' => $leaderCounts['new'].' report baru menunggu diterima',
                'meta' => null,
                'url' => route('tickets.inbox', ['view' => 'new']),
                'tone' => 'zinc',
            ];
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * Blocked first (with the reason), then overdue.
     *
     * @return list<array{kind: string, label: string, title: string, meta: string|null, url: string, tone: string}>
     */
    private function tasks(User $user): array
    {
        $items = [];

        $blocked = Task::query()->visibleTo($user)->assignedTo($user)->blocked()
            ->latest('blocked_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'number', 'title', 'blocked_reason']);

        foreach ($blocked as $task) {
            $items[] = [
                'kind' => 'task',
                'label' => 'Blocked Task',
                'title' => $task->title,
                'meta' => $task->blocked_reason,
                'url' => route('tasks.show', $task),
                'tone' => 'red',
            ];
        }

        $overdue = Task::query()->visibleTo($user)->assignedTo($user)->overdue()
            ->whereDoesntHave('status', fn ($status) => $status->where('is_blocked', true))
            ->orderBy('current_end')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'number', 'title', 'current_end']);

        foreach ($overdue as $task) {
            $items[] = [
                'kind' => 'task',
                'label' => 'Overdue Task',
                'title' => $task->title,
                'meta' => 'Deadline '.$task->current_end?->translatedFormat('d M Y'),
                'url' => route('tasks.show', $task),
                'tone' => 'red',
            ];
        }

        return $items;
    }

    /**
     * Reports waiting for the user (TicketQueue "waiting_for_me"), with the
     * reason derived from status flags.
     *
     * @return list<array{kind: string, label: string, title: string, meta: string|null, url: string, tone: string}>
     */
    private function reports(User $user): array
    {
        $query = $this->queue->base($user)->select('tickets.*')->with('status');
        $this->queue->apply($query, 'waiting_for_me', $user);
        $this->queue->orderActionable($query, $user);

        return array_values($query->limit(self::PER_SOURCE)->get()
            ->map(fn (Ticket $ticket): array => [
                'kind' => 'report',
                'label' => match (true) {
                    $ticket->requester_user_id === $user->id && $ticket->status->is_blocked => 'Report waiting for information',
                    $ticket->requester_user_id === $user->id && $ticket->status->awaits_review => 'Report waiting for review',
                    $ticket->requester_user_id === $user->id && $ticket->status->counts_as_completed => 'Report ready to close',
                    default => 'Report assigned to you',
                },
                'title' => $ticket->title,
                'meta' => $ticket->number,
                'url' => route('tickets.show', $ticket),
                'tone' => 'amber',
            ])
            ->all());
    }
}
