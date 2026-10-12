<?php

namespace App\Domain\Workflow\Support;

use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Result of a transition request: executed immediately, or waiting for an
 * approval request.
 */
final readonly class TransitionOutcome
{
    public function __construct(
        public Model $record,
        public ?ApprovalRequest $approval = null,
    ) {}

    public function awaitsApproval(): bool
    {
        return $this->approval !== null;
    }
}
