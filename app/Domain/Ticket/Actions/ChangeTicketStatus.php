<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Domain\Workflow\Support\TransitionContext;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Enums\CommentKind;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketRejectionReason;
use App\Models\TicketRelation;
use App\Models\TicketRelationType;
use App\Models\TicketResolution;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for ticket status (ADR-057). Each public method is one
 * lifecycle step validated against the ticket's workflow version on the
 * locked row (WorkflowGate, ADR-066), so double
 * clicks and stale screens cannot apply a step twice or skip one.
 *
 * Messages that belong to a step (information request/answer, revision
 * reason) are stored as typed comments; the step itself is activity.
 */
class ChangeTicketStatus
{
    public function __construct(
        private TicketGuard $guard,
        private WorkflowGate $workflow,
        private TicketAudit $audit,
        private AddTicketComment $comments,
    ) {}

    /**
     * Assigned/Revision/Reopened → In Progress. started_at is set once.
     */
    public function start(Ticket $ticket, User $actor, ?TransitionContext $context = null): Ticket
    {
        return $this->transition($ticket, 'start', $actor, function (Ticket $ticket, Status $from, Status $to): array {
            if (! $ticket->picAssignment()->exists()) {
                throw new BusinessRuleException('Tetapkan PIC terlebih dahulu sebelum memulai pekerjaan.');
            }

            $this->record($ticket, $from->counts_as_started ? 'ticket.status_changed' : 'ticket.started',
                ($from->counts_as_started ? 'Melanjutkan pengerjaan ticket ' : 'Mulai mengerjakan ticket ').$ticket->number, $from, $to);

            return $ticket->started_at === null ? ['started_at' => Date::now()] : [];
        }, $context, null);
    }

    /**
     * Pause the ticket until the requester answers; the current status is
     * remembered and restored by provideInformation().
     */
    public function requestInformation(Ticket $ticket, string $message, User $actor, ?TransitionContext $context = null): Ticket
    {
        $this->requireText($message, 'Pertanyaan atau informasi yang dibutuhkan wajib diisi.');

        return $this->transition($ticket, 'request_information', $actor, function (Ticket $ticket, Status $from, Status $to) use ($message, $actor): array {
            $comment = $this->comments->handle($ticket, $message, $actor, CommentKind::InformationRequest, audit: false);
            $this->record($ticket, 'ticket.information_requested', 'Meminta informasi tambahan untuk ticket '.$ticket->number, $from, $to, ['comment_id' => $comment->id]);

            return ['resume_status_id' => $from->id];
        }, $context, $message);
    }

    /**
     * Answer an information request: back to the status before the request.
     */
    public function provideInformation(Ticket $ticket, string $message, User $actor, ?TransitionContext $context = null): Ticket
    {
        $this->requireText($message, 'Informasi yang diminta wajib diisi.');
        $this->guard->ensureNotFinal($ticket);

        return DB::transaction(function () use ($ticket, $message, $actor, $context): Ticket {
            $ticket = $this->lock($ticket);
            $from = $ticket->status;
            $resume = $ticket->resumeStatus;

            if (! $from->is_blocked || $resume === null) {
                throw new BusinessRuleException('Ticket ini tidak sedang menunggu informasi. Muat ulang data terbaru.');
            }

            $transition = $this->workflow->resolve($ticket, $from, $resume, $context, 'provide_information');

            $comment = $this->comments->handle($ticket, $message, $actor, CommentKind::InformationProvided, audit: false);
            $ticket->forceFill(['status_id' => $resume->id, 'resume_status_id' => null, 'updated_by' => $actor->id])->save();

            $this->record($ticket, 'ticket.information_provided', 'Memberikan informasi untuk ticket '.$ticket->number.' (kembali ke '.$resume->name.')', $from, $resume, ['comment_id' => $comment->id]);
            $this->workflow->record($ticket, $transition, $from, $resume, $actor, $message, array_filter(['approval_request_id' => $context?->approvalRequestId]));

            return $ticket;
        });
    }

    public function sendToReview(Ticket $ticket, User $actor, ?string $note = null, ?TransitionContext $context = null): Ticket
    {
        return $this->transition($ticket, 'send_to_review', $actor, function (Ticket $ticket, Status $from, Status $to) use ($note, $actor): array {
            $comment = filled($note) ? $this->comments->handle($ticket, (string) $note, $actor, audit: false) : null;
            $this->record($ticket, 'ticket.sent_to_review', 'Mengirim ticket '.$ticket->number.' untuk direview', $from, $to, ['comment_id' => $comment?->id]);

            return [];
        }, $context, $note);
    }

    public function requestRevision(Ticket $ticket, string $reason, User $actor, ?TransitionContext $context = null): Ticket
    {
        $this->requireText($reason, 'Alasan revisi wajib diisi.');

        return $this->transition($ticket, 'request_revision', $actor, function (Ticket $ticket, Status $from, Status $to) use ($reason, $actor): array {
            $comment = $this->comments->handle($ticket, $reason, $actor, CommentKind::RevisionRequest, audit: false);
            $this->record($ticket, 'ticket.revision_requested', 'Meminta revisi ticket '.$ticket->number, $from, $to, ['comment_id' => $comment->id, 'reason' => $reason]);

            return [];
        }, $context, $reason);
    }

    /**
     * In Review → Resolved with a resolution. Resolutions such as Duplicate
     * require the primary ticket, linked with the duplicate relation type.
     */
    public function resolve(Ticket $ticket, ?int $resolutionId, ?string $notes, User $actor, ?int $primaryTicketId = null, ?TransitionContext $context = null): Ticket
    {
        /** @var TicketResolution $resolution */
        $resolution = $this->guard->master(TicketResolution::class, $resolutionId, 'Resolution', required: true);
        $this->requireText($notes, 'Catatan resolusi wajib diisi.');

        $primary = null;

        if ($resolution->requires_related_ticket) {
            $primary = $primaryTicketId !== null ? Ticket::query()->find($primaryTicketId) : null;

            if ($primary === null || $primary->id === $ticket->id || ! $primary->isVisibleTo($actor)) {
                throw new BusinessRuleException('Resolution '.$resolution->name.' membutuhkan ticket utama yang valid.');
            }
        }

        return $this->transition($ticket, 'resolve', $actor, function (Ticket $ticket, Status $from, Status $to) use ($resolution, $notes, $actor, $primary): array {
            if ($primary !== null) {
                $this->linkDuplicate($ticket, $primary, $actor);
            }

            $this->record($ticket, 'ticket.resolved', 'Menyelesaikan ticket '.$ticket->number.' ('.$resolution->name.')', $from, $to,
                ['resolution' => $resolution->name, 'primary_ticket' => $primary?->number, 'note' => $notes]);

            return [
                'resolution_id' => $resolution->id,
                'resolution_notes' => $notes,
                'resolved_by' => $actor->id,
                'resolved_at' => Date::now(),
            ];
        }, $context, $notes);
    }

    public function close(Ticket $ticket, User $actor, ?TransitionContext $context = null): Ticket
    {
        return $this->transition($ticket, 'close', $actor, function (Ticket $ticket, Status $from, Status $to) use ($actor): array {
            $this->record($ticket, 'ticket.closed', 'Menutup ticket '.$ticket->number, $from, $to);

            return ['closed_by' => $actor->id, 'closed_at' => Date::now()];
        }, $context, null);
    }

    /**
     * Resolved/Closed → Reopened. The previous resolution and closure stay in
     * the activity log (old values); the ticket itself is active again.
     */
    public function reopen(Ticket $ticket, string $reason, User $actor, ?TransitionContext $context = null): Ticket
    {
        $this->requireText($reason, 'Alasan membuka kembali ticket wajib diisi.');

        return $this->transition($ticket, 'reopen', $actor, function (Ticket $ticket, Status $from, Status $to) use ($reason): array {
            $ticket->loadMissing('resolution:id,name');

            $this->audit->record($ticket, 'ticket.reopened', 'Membuka kembali ticket '.$ticket->number,
                old: [
                    'status' => $from->name,
                    'resolution' => $ticket->resolution?->name,
                    'resolution_notes' => $ticket->resolution_notes,
                    'resolved_at' => $ticket->resolved_at?->toDateTimeString(),
                    'closed_at' => $ticket->closed_at?->toDateTimeString(),
                ],
                new: ['status' => $to->name],
                properties: ['reason' => $reason],
            );

            return [
                'resolution_id' => null,
                'resolution_notes' => null,
                'resolved_by' => null,
                'resolved_at' => null,
                'closed_by' => null,
                'closed_at' => null,
                'reopen_count' => $ticket->reopen_count + 1,
                'last_reopened_at' => Date::now(),
            ];
        }, $context, $reason);
    }

    /**
     * Reject an invalid intake (only before work starts).
     */
    public function reject(Ticket $ticket, ?int $reasonId, ?string $notes, User $actor, ?TransitionContext $context = null): Ticket
    {
        /** @var TicketRejectionReason $reason */
        $reason = $this->guard->master(TicketRejectionReason::class, $reasonId, 'Alasan penolakan', required: true);
        $this->requireText($notes, 'Catatan penolakan wajib diisi agar requester memahami alasannya.');

        return $this->transition($ticket, 'reject', $actor, function (Ticket $ticket, Status $from, Status $to) use ($reason, $notes): array {
            $this->record($ticket, 'ticket.rejected', 'Menolak ticket '.$ticket->number.' ('.$reason->name.')', $from, $to, ['reason' => $notes]);

            return ['rejection_reason_id' => $reason->id, 'rejection_notes' => $notes];
        }, $context, $notes);
    }

    public function cancel(Ticket $ticket, string $reason, User $actor, ?TransitionContext $context = null): Ticket
    {
        $this->requireText($reason, 'Alasan pembatalan wajib diisi.');

        return $this->transition($ticket, 'cancel', $actor, function (Ticket $ticket, Status $from, Status $to) use ($reason): array {
            $this->record($ticket, 'ticket.cancelled', 'Membatalkan ticket '.$ticket->number, $from, $to, ['reason' => $reason]);

            return [];
        }, $context, $reason);
    }

    /**
     * Lock, validate the step from the CURRENT status, apply side effects.
     *
     * @param  Closure(Ticket, Status, Status): array<string, mixed>  $effects
     */
    private function transition(Ticket $ticket, string $operation, User $actor, Closure $effects, ?TransitionContext $context = null, ?string $comment = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $operation, $actor, $effects, $context, $comment): Ticket {
            $ticket = $this->lock($ticket);
            $from = $ticket->status;
            $transition = $this->workflow->resolve($ticket, $from, null, $context, $operation);
            $to = $transition->toState->status;

            $attributes = $effects($ticket, $from, $to);

            $ticket->forceFill([...$attributes, 'status_id' => $to->id, 'updated_by' => $actor->id])->save();

            if (! $to->is_blocked && $ticket->resume_status_id !== null && ! array_key_exists('resume_status_id', $attributes)) {
                $ticket->forceFill(['resume_status_id' => null])->save();
            }

            $this->workflow->record($ticket, $transition, $from, $to, $actor, $comment, array_filter(['approval_request_id' => $context?->approvalRequestId]));

            return $ticket->setRelation('status', $to);
        });
    }

    /**
     * Generic workflow step without ticket-specific side effects (statuses
     * added by administrators, e.g. "QA Review"). Always targets the chosen
     * transition.
     */
    public function move(Ticket $ticket, User $actor, TransitionContext $context, ?string $comment = null): Ticket
    {
        return $this->transition($ticket, 'move', $actor, function (Ticket $ticket, Status $from, Status $to) use ($comment, $actor): array {
            $commentModel = filled($comment) ? $this->comments->handle($ticket, (string) $comment, $actor, audit: false) : null;
            $this->record($ticket, 'ticket.status_changed', 'Mengubah status ticket '.$ticket->number.' menjadi '.$to->name, $from, $to, ['comment_id' => $commentModel?->id]);

            return [];
        }, $context, $comment);
    }

    private function lock(Ticket $ticket): Ticket
    {
        return Ticket::query()->with(['status', 'resumeStatus'])->lockForUpdate()->findOrFail($ticket->id);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(Ticket $ticket, string $event, string $description, Status $from, Status $to, array $properties = []): void
    {
        $this->audit->record($ticket, $event, $description, old: ['status' => $from->name], new: ['status' => $to->name], properties: $properties);
    }

    private function requireText(?string $value, string $message): void
    {
        if (trim((string) $value) === '') {
            throw new BusinessRuleException($message);
        }
    }

    private function linkDuplicate(Ticket $ticket, Ticket $primary, User $actor): void
    {
        $type = TicketRelationType::query()->active()->where('is_duplicate', true)->first()
            ?? throw new BusinessRuleException('Belum ada Relation Type untuk duplikat. Atur di Master Data.');

        $exists = TicketRelation::query()
            ->where(fn ($query) => $query->where('ticket_id', $ticket->id)->where('related_ticket_id', $primary->id))
            ->orWhere(fn ($query) => $query->where('ticket_id', $primary->id)->where('related_ticket_id', $ticket->id))
            ->exists();

        if (! $exists) {
            TicketRelation::query()->create([
                'ticket_id' => $ticket->id,
                'related_ticket_id' => $primary->id,
                'ticket_relation_type_id' => $type->id,
                'created_by' => $actor->id,
            ]);
        }
    }
}
