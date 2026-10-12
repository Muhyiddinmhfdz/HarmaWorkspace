<?php

namespace App\Domain\Project\Support;

use App\Enums\MasterScope;
use App\Models\Status;
use Illuminate\Support\Collection;

/**
 * Basic project state machine, until the Workflow Engine exists.
 *
 * The status master owns presentation and semantic flags; this class owns
 * which transitions are allowed. Rules are keyed by the stable status code
 * (codes lock once used). Statuses added by administrators without a rule
 * fall back to a conservative, flag-based policy.
 *
 * Archiving is not a status change: it is ArchiveProject on a terminal status.
 */
class ProjectLifecycle
{
    /**
     * Allowed targets per status code.
     *
     * @var array<string, list<string>>
     */
    public const array TRANSITIONS = [
        'DRAFT' => ['PLANNING', 'CANCELLED'],
        'PLANNING' => ['ACTIVE', 'ON_HOLD', 'CANCELLED'],
        'ACTIVE' => ['ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * Friendly action labels for known transitions ("from>to" or "*>to").
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        '*>PLANNING' => 'Move to planning',
        'PLANNING>ACTIVE' => 'Start project',
        'ON_HOLD>ACTIVE' => 'Resume project',
        '*>ON_HOLD' => 'Put on hold',
        '*>CANCELLED' => 'Cancel project',
    ];

    public function allows(Status $from, Status $to): bool
    {
        if ($from->id === $to->id || $from->scope !== MasterScope::Project || $to->scope !== MasterScope::Project) {
            return false;
        }

        if (array_key_exists($from->code, self::TRANSITIONS)) {
            return in_array($to->code, self::TRANSITIONS[$from->code], true);
        }

        // Custom (administrator-defined) status without an explicit rule:
        // terminal statuses never move; others may move to any non-initial status.
        return ! $from->is_terminal && ! $to->is_initial;
    }

    /**
     * Active project statuses reachable from the given status.
     *
     * @return Collection<int, Status>
     */
    public function targets(Status $from): Collection
    {
        return Status::query()
            ->forScope(MasterScope::Project)
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
            ?? ($to->is_terminal && ! $to->counts_as_completed ? 'Cancel project' : 'Move to '.$to->name);
    }

    public function rejectionMessage(Status $from, Status $to): string
    {
        return 'Status Project tidak dapat diubah dari '.$from->name.' ke '.$to->name.'.';
    }
}
