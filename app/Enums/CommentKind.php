<?php

namespace App\Enums;

/**
 * Technical meaning of a conversation message. Lifecycle steps that need a
 * message (request information, provide information, request revision)
 * store it as a typed comment so the conversation stays the single place
 * for user-written text, while the activity log keeps the system event.
 */
enum CommentKind: string
{
    case Comment = 'comment';
    case InformationRequest = 'information_request';
    case InformationProvided = 'information_provided';
    case RevisionRequest = 'revision_request';

    public function label(): ?string
    {
        return match ($this) {
            self::Comment => null,
            self::InformationRequest => 'Meminta informasi',
            self::InformationProvided => 'Memberi informasi',
            self::RevisionRequest => 'Meminta revisi',
        };
    }
}
