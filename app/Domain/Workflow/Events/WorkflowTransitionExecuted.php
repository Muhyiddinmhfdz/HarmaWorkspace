<?php

namespace App\Domain\Workflow\Events;

use App\Models\WorkflowTransitionHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after a governed status change is committed. No listeners yet:
 * extension point for notifications, SLA and automation phases.
 */
class WorkflowTransitionExecuted
{
    use Dispatchable;

    public function __construct(
        public Model $subject,
        public WorkflowTransitionHistory $history,
    ) {}
}
