<?php

namespace App\Domain\Task\Support;

use App\Enums\MasterScope;
use App\Models\Status;
use Illuminate\Support\Collection;

/**
 * Basic task state machine until the Workflow Engine exists (ADR-041).
 * Rules use stable status codes; administrator-defined statuses without a
 * rule fall back to a conservative flag-based policy.
 */
class TaskLifecycle
{
    /**
     * @var array<string, list<string>>
     */
    public const array TRANSITIONS = [
        'BACKLOG' => ['TODO', 'CANCELLED'],
        'TODO' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['BLOCKED', 'IN_REVIEW', 'CANCELLED'],
        'BLOCKED' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_REVIEW' => ['DONE', 'REVISION'],
        'REVISION' => ['IN_PROGRESS', 'IN_REVIEW', 'CANCELLED'],
        'DONE' => ['REVISION'],
        'CANCELLED' => ['TODO'],
    ];

    /**
     * @var array<string, string>
     */
    private const array LABELS = [
        '*>TODO' => 'Move to To Do',
        'CANCELLED>TODO' => 'Reopen task',
        '*>IN_PROGRESS' => 'Start work',
        'BLOCKED>IN_PROGRESS' => 'Resume task',
        'REVISION>IN_PROGRESS' => 'Continue work',
        '*>BLOCKED' => 'Mark as blocked',
        '*>IN_REVIEW' => 'Send to review',
        '*>DONE' => 'Mark as done',
        '*>REVISION' => 'Request revision',
        'DONE>REVISION' => 'Reopen for revision',
        '*>CANCELLED' => 'Cancel task',
    ];

    public function allows(Status $from, Status $to): bool
    {
        if ($from->id === $to->id || $from->scope !== MasterScope::Task || $to->scope !== MasterScope::Task) {
            return false;
        }

        if (array_key_exists($from->code, self::TRANSITIONS)) {
            return in_array($to->code, self::TRANSITIONS[$from->code], true);
        }

        return ! $from->is_terminal && ! $to->is_initial;
    }

    /**
     * @return Collection<int, Status>
     */
    public function targets(Status $from): Collection
    {
        return Status::query()
            ->forScope(MasterScope::Task)
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Status $to): bool => $this->allows($from, $to))
            ->values();
    }

    public function label(Status $from, Status $to): string
    {
        return self::LABELS[$from->code.'>'.$to->code]
            ?? self::LABELS['*>'.$to->code]
            ?? 'Move to '.$to->name;
    }

    public function rejectionMessage(Status $from, Status $to): string
    {
        return 'Status Task tidak dapat diubah dari '.$from->name.' ke '.$to->name.'.';
    }

    /**
     * Policy ability needed for a transition.
     */
    public function abilityFor(Status $from, Status $to): string
    {
        return match (true) {
            $from->is_terminal => 'complete',
            $to->counts_as_completed => 'complete',
            $to->is_terminal => 'cancel',
            default => 'work',
        };
    }
}
