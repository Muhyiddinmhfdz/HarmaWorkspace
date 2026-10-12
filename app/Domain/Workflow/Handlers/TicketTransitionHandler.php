<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Workflow\Support\TransitionContext;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps ticket transitions to the named operations of ChangeTicketStatus
 * (Phase 4 behaviour) plus a generic "move" for custom statuses.
 */
class TicketTransitionHandler implements TransitionHandler
{
    public function __construct(
        private ChangeTicketStatus $status,
        private ManageTicketAssignments $assignments,
    ) {}

    public function operations(): array
    {
        return [
            'move' => 'Change status (optional comment)',
            'assign' => 'Assign PIC (runs when a PIC is assigned)',
            'start' => 'Start / continue work (records start time, needs a PIC)',
            'request_information' => 'Request information (waits for the requester)',
            'provide_information' => 'Provide information (returns to the previous status)',
            'send_to_review' => 'Send to review',
            'request_revision' => 'Request revision (reason)',
            'resolve' => 'Resolve (resolution + notes)',
            'close' => 'Close ticket',
            'reopen' => 'Reopen (reason, keeps history)',
            'reject' => 'Reject intake (reason + notes)',
            'cancel' => 'Cancel ticket (reason)',
        ];
    }

    public function incompatibility(string $operation, Status $from, Status $to): ?string
    {
        $finished = $to->is_terminal || $to->counts_as_completed;

        return match ($operation) {
            'move', 'start', 'send_to_review', 'request_revision', 'assign' => $finished ? 'Operasi ini tidak boleh menuju status selesai/final.' : null,
            'request_information' => $to->is_blocked ? null : 'Request information harus menuju status "waiting".',
            'provide_information' => $from->is_blocked ? null : 'Provide information hanya dari status "waiting".',
            'resolve' => $to->counts_as_completed && ! $to->is_terminal ? null : 'Resolve harus menuju status "resolved" (completed, belum final).',
            'close' => $to->counts_as_completed && $to->is_terminal ? null : 'Close harus menuju status "closed" (completed dan final).',
            'reopen' => ($from->counts_as_completed || $from->is_terminal) && ! $finished ? null : 'Reopen hanya dari status selesai menuju status aktif.',
            'reject', 'cancel' => $to->is_terminal && ! $to->counts_as_completed ? null : 'Operasi ini harus menuju status final yang tidak selesai.',
            default => 'Operasi tidak dikenal untuk Ticket.',
        };
    }

    public function isAvailable(Model $record, WorkflowTransition $transition): bool
    {
        return match ($transition->operation) {
            'provide_information' => $this->ticket($record)->resume_status_id === $transition->toState->status_id,
            'assign' => false, // Runs through the Assign PIC action.
            default => true,
        };
    }

    public function precheck(Model $record, WorkflowTransition $transition, array $input): void
    {
        if ($transition->operation === 'start' && ! $this->ticket($record)->picAssignment()->exists()) {
            throw new BusinessRuleException('Tetapkan PIC terlebih dahulu sebelum memulai pekerjaan.');
        }
    }

    public function execute(Model $record, WorkflowTransition $transition, User $actor, array $input, TransitionContext $context): Model
    {
        $ticket = $this->ticket($record);
        $text = fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
        $int = fn (string $key): ?int => isset($input[$key]) && is_numeric($input[$key]) ? (int) $input[$key] : null;

        return match ($transition->operation) {
            'start' => $this->status->start($ticket, $actor, $context),
            'request_information' => $this->status->requestInformation($ticket, $text('comment'), $actor, $context),
            'provide_information' => $this->status->provideInformation($ticket, $text('comment'), $actor, $context),
            'send_to_review' => $this->status->sendToReview($ticket, $actor, $text('comment') ?: null, $context),
            'request_revision' => $this->status->requestRevision($ticket, $text('reason') ?: $text('comment'), $actor, $context),
            'resolve' => $this->status->resolve($ticket, $int('resolution_id'), $text('notes') ?: $text('comment'), $actor, $int('primary_ticket_id'), $context),
            'close' => $this->status->close($ticket, $actor, $context),
            'reopen' => $this->status->reopen($ticket, $text('reason'), $actor, $context),
            'reject' => $this->status->reject($ticket, $int('rejection_reason_id'), $text('notes') ?: $text('reason'), $actor, $context),
            'cancel' => $this->status->cancel($ticket, $text('reason'), $actor, $context),
            'assign' => $this->assignments->assign($ticket, (int) $int('user_id'), $actor),
            default => $this->status->move($ticket, $actor, $context, $text('comment') ?: null),
        };
    }

    private function ticket(Model $record): Ticket
    {
        return $record instanceof Ticket ? $record : throw new InvalidArgumentException('Ticket expected.');
    }
}
