<?php

namespace App\Enums;

enum ApprovalStepStatus: string
{
    /** Later step, not started yet. */
    case Waiting = 'waiting';

    /** Current step, waiting for decisions. */
    case Pending = 'pending';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';

    /** Optional step without an eligible approver, or request ended earlier. */
    case Skipped = 'skipped';
}
