<?php

namespace App\Domain\Workflow\Support;

use App\Models\WorkflowTransition;

/**
 * How a state change reached a domain action: through a specific workflow
 * transition chosen in the UI, and/or after a completed approval.
 */
final readonly class TransitionContext
{
    public function __construct(
        public ?WorkflowTransition $transition = null,
        public bool $approved = false,
        public ?int $approvalRequestId = null,
    ) {}
}
