<?php

namespace App\Enums;

/**
 * Finite, data-only transition rules. No expression, code or SQL is ever
 * stored: each type has a validated config shape and a fixed evaluator.
 *
 * - permission: {permissions: list<string>} — actor holds any of them;
 * - context_role: {roles: list<string>} — actor holds any contextual role;
 * - comment_required / reason_required: the request must carry the text;
 * - attachment_required: the record must have at least one attachment;
 * - assignee_required: the record must have an active Main PIC;
 * - resolution_required: a resolution must be chosen (ticket resolve).
 *
 * "Who" rules (permission, context_role) combine with OR; requirement rules
 * must all be satisfied.
 */
enum WorkflowRuleType: string
{
    case Permission = 'permission';
    case ContextRole = 'context_role';
    case CommentRequired = 'comment_required';
    case ReasonRequired = 'reason_required';
    case AttachmentRequired = 'attachment_required';
    case AssigneeRequired = 'assignee_required';
    case ResolutionRequired = 'resolution_required';

    public function label(): string
    {
        return match ($this) {
            self::Permission => 'Permission',
            self::ContextRole => 'Contextual role',
            self::CommentRequired => 'Comment required',
            self::ReasonRequired => 'Reason required',
            self::AttachmentRequired => 'Attachment required',
            self::AssigneeRequired => 'Main PIC required',
            self::ResolutionRequired => 'Resolution required',
        };
    }

    public function isWhoRule(): bool
    {
        return $this === self::Permission || $this === self::ContextRole;
    }
}
