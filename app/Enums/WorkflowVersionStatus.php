<?php

namespace App\Enums;

/**
 * Draft versions are editable; published versions are immutable; archived
 * versions are superseded but still drive the records bound to them.
 */
enum WorkflowVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Published => 'green',
            self::Archived => 'zinc',
        };
    }
}
