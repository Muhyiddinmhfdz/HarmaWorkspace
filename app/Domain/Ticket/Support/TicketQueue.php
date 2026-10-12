<?php

namespace App\Domain\Ticket\Support;

use App\Enums\MasterScope;
use App\Enums\TicketAssignmentRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Semantic ticket queues (New, Unassigned, Waiting, Review, Overdue, ...)
 * shared by the Department Inbox, ticket lists, My Tickets and dashboards.
 *
 * Each queue is defined once as an SQL condition over status flags (never
 * names), so the list filter and its counter can never disagree, and all
 * counters of a page come from a single aggregate query.
 */
class TicketQueue
{
    /**
     * Alias of the joined statuses table.
     */
    public const string STATUS = 'qs';

    private const string ACTIVE = 'qs.is_terminal = 0 AND qs.counts_as_completed = 0';

    /**
     * Column aliases for aggregate counters (literal, never user input).
     *
     * @var list<literal-string>
     */
    private const array ALIASES = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'c9', 'c10', 'c11', 'c12', 'c13', 'c14', 'c15', 'c16', 'c17', 'c18', 'c19'];

    /**
     * Visible tickets with their status joined (no select yet).
     *
     * @return Builder<Ticket>
     */
    public function base(User $user): Builder
    {
        return Ticket::query()
            ->visibleTo($user)
            ->join('statuses as '.self::STATUS, self::STATUS.'.id', '=', 'tickets.status_id');
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    public function apply(Builder $query, string $queue, User $user): void
    {
        $conditions = $this->conditions($user);

        if (isset($conditions[$queue])) {
            [$sql, $bindings] = $conditions[$queue];
            $query->whereRaw('('.$sql.')', $bindings);
        }
    }

    /**
     * Counts for the given queues in one aggregate query.
     *
     * @param  Builder<Ticket>  $base
     * @param  list<string>  $queues
     * @return array<string, int>
     */
    public function counts(Builder $base, array $queues, User $user): array
    {
        $conditions = $this->conditions($user);
        $selects = [];
        $bindings = [];

        foreach ($queues as $index => $queue) {
            [$sql, $queueBindings] = $conditions[$queue] ?? ['1 = 1', []];
            $selects[] = 'COALESCE(SUM(CASE WHEN ('.$sql.') THEN 1 ELSE 0 END), 0) AS '.(self::ALIASES[$index] ?? 'c_overflow');
            array_push($bindings, ...$queueBindings);
        }

        $row = (array) ((clone $base)->toBase()->selectRaw(implode(', ', $selects), $bindings)->first() ?? []);

        $counts = [];

        foreach ($queues as $index => $queue) {
            $counts[$queue] = (int) ($row[self::ALIASES[$index] ?? 'c_overflow'] ?? 0);
        }

        return $counts;
    }

    /**
     * Actionable order (My Tickets & lists): waiting for the user, overdue,
     * due today, in progress, other active, then resolved/closed last.
     *
     * @param  Builder<Ticket>  $query
     */
    public function orderActionable(Builder $query, User $user): void
    {
        $conditions = $this->conditions($user);
        $cases = [];
        $bindings = [];

        foreach (['waiting_for_me', 'overdue', 'due_today', 'in_progress', 'open'] as $rank => $queue) {
            [$sql, $queueBindings] = $conditions[$queue];
            $cases[] = 'WHEN ('.$sql.') THEN ?';
            array_push($bindings, ...$queueBindings);
            $bindings[] = $rank;
        }

        $query->orderByRaw('CASE '.implode(' ', $cases).' ELSE 9 END', $bindings)
            ->orderByRaw('tickets.current_due_at IS NULL')
            ->orderBy('tickets.current_due_at')
            ->orderByDesc('tickets.updated_at');
    }

    /**
     * Queue conditions: SQL defined in code (literal strings), every value
     * passed as a binding.
     *
     * @return array<string, array{0: literal-string, 1: list<mixed>}>
     */
    public function conditions(User $user): array
    {
        $active = self::ACTIVE;
        $now = Date::now();
        $pic = TicketAssignmentRole::Pic->value;
        $collaborator = TicketAssignmentRole::Collaborator->value;
        $scope = MasterScope::Ticket->value;
        $hasPic = 'EXISTS (SELECT 1 FROM ticket_assignments qa WHERE qa.ticket_id = tickets.id AND qa.ended_at IS NULL AND qa.role = ?)';
        $assignedAs = 'EXISTS (SELECT 1 FROM ticket_assignments qa WHERE qa.ticket_id = tickets.id AND qa.ended_at IS NULL AND qa.user_id = ? AND qa.role = ?)';
        $assigned = 'EXISTS (SELECT 1 FROM ticket_assignments qa WHERE qa.ticket_id = tickets.id AND qa.ended_at IS NULL AND qa.user_id = ?)';
        $notStarted = 'qs.counts_as_started = 0 AND qs.is_blocked = 0 AND qs.awaits_review = 0';
        $working = 'qs.counts_as_started = 1 AND qs.is_blocked = 0 AND qs.awaits_review = 0';
        $resolved = 'qs.counts_as_completed = 1 AND qs.is_terminal = 0';
        $topPriority = 'tickets.priority_id IN (SELECT p.id FROM priorities p WHERE p.scope = ? AND p.level >= (SELECT MAX(pm.level) FROM priorities pm WHERE pm.scope = ? AND pm.is_active = 1))';

        return [
            'all' => ['1 = 1', []],
            'open' => [$active, []],
            'new' => [$active.' AND tickets.accepted_at IS NULL AND qs.is_blocked = 0', []],
            'unassigned' => [$active.' AND tickets.accepted_at IS NOT NULL AND NOT '.$hasPic, [$pic]],
            'assigned' => [$active.' AND '.$hasPic.' AND '.$notStarted, [$pic]],
            'in_progress' => [$active.' AND '.$working, []],
            'waiting' => [$active.' AND qs.is_blocked = 1', []],
            'review' => [$active.' AND qs.awaits_review = 1', []],
            'overdue' => [$active.' AND tickets.current_due_at IS NOT NULL AND tickets.current_due_at < ?', [$now]],
            'due_today' => [$active.' AND tickets.current_due_at BETWEEN ? AND ?', [$now->startOfDay(), $now->endOfDay()]],
            'critical' => [$active.' AND '.$topPriority, [$scope, $scope]],
            'resolved' => [$resolved, []],
            'closed' => ['qs.counts_as_completed = 1 AND qs.is_terminal = 1', []],
            'mine' => ['(tickets.requester_user_id = ? OR '.$assigned.')', [$user->id, $user->id]],
            'requested' => [$active.' AND tickets.requester_user_id = ?', [$user->id]],
            'assigned_to_me' => [$active.' AND '.$assignedAs, [$user->id, $pic]],
            'collaborating' => [$active.' AND '.$assignedAs, [$user->id, $collaborator]],
            'waiting_for_me' => [
                '(tickets.requester_user_id = ? AND (('.$active.' AND (qs.is_blocked = 1 OR qs.awaits_review = 1)) OR ('.$resolved.')))'
                .' OR ('.$active.' AND '.$notStarted.' AND '.$assignedAs.')',
                [$user->id, $user->id, $pic],
            ],
            'my_resolved' => [$resolved.' AND (tickets.requester_user_id = ? OR '.$assigned.')', [$user->id, $user->id]],
            'my_closed' => ['qs.is_terminal = 1 AND (tickets.requester_user_id = ? OR '.$assigned.')', [$user->id, $user->id]],
        ];
    }
}
