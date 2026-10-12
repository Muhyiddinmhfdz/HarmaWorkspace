<?php

namespace App\Enums;

enum ApprovalDecisionType: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Revision = 'revision';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::Reject => 'Rejected',
            self::Revision => 'Revision requested',
        };
    }

    public function requiresComment(): bool
    {
        return $this !== self::Approve;
    }
}
