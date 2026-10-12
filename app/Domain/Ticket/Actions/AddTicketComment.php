<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\InputSanitizer;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Enums\CommentKind;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;

/**
 * Add a conversation message. Plain text only (tags and control characters
 * removed, newlines kept); final tickets accept no new messages.
 */
class AddTicketComment
{
    public const int MAX_LENGTH = 5000;

    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
        private InputSanitizer $sanitizer,
    ) {}

    public function handle(Ticket $ticket, string $body, User $actor, CommentKind $kind = CommentKind::Comment, bool $audit = true): Comment
    {
        $this->guard->ensureNotFinal($ticket);

        $body = (string) $this->sanitizer->clean($body);

        if ($body === '') {
            throw new BusinessRuleException('Pesan tidak boleh kosong.');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new BusinessRuleException('Pesan maksimal '.self::MAX_LENGTH.' karakter.');
        }

        $comment = $ticket->comments()->create([
            'user_id' => $actor->id,
            'kind' => $kind,
            'body' => $body,
        ]);

        if ($audit) {
            $this->audit->record($ticket, 'ticket.comment_added', 'Menambahkan komentar', properties: ['comment_id' => $comment->id]);
        }

        return $comment;
    }
}
