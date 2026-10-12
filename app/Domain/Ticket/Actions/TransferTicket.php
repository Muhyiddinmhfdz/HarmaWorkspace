<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketTransfer;
use App\Models\TicketTransferReason;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Move a ticket to another department (ADR-055). Ownership moves entirely:
 * active PIC and collaborators are ended, acceptance is reset and the ticket
 * returns to the initial state of its workflow (the new department's inbox). Nothing is
 * deleted; the transfer row and the activity keep the history.
 */
class TransferTicket
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
        private WorkflowGate $workflow,
    ) {}

    public function handle(Ticket $ticket, int $departmentId, ?int $reasonId, ?string $notes, User $actor): Ticket
    {
        $this->guard->ensureActive($ticket);
        $target = $this->guard->department($departmentId);
        /** @var TicketTransferReason $reason */
        $reason = $this->guard->master(TicketTransferReason::class, $reasonId, 'Alasan transfer', required: true);

        return DB::transaction(function () use ($ticket, $target, $reason, $notes, $actor): Ticket {
            $ticket = Ticket::query()->with(['status', 'currentDepartment:id,name'])->lockForUpdate()->findOrFail($ticket->id);
            $this->guard->ensureActive($ticket);

            if ($ticket->current_department_id === $target->id) {
                throw new BusinessRuleException('Ticket sudah berada di Department '.$target->name.'.');
            }

            $from = $ticket->currentDepartment;
            $now = Date::now();
            $initial = $this->workflow->initialStatus($ticket);
            $previous = $ticket->status;

            $ended = $ticket->activeAssignments()->with('user:id,name')->get();
            $ended->each(fn (TicketAssignment $assignment): bool => $assignment->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save());

            TicketTransfer::query()->create([
                'ticket_id' => $ticket->id,
                'from_department_id' => $from->id,
                'to_department_id' => $target->id,
                'transfer_reason_id' => $reason->id,
                'notes' => filled($notes) ? $notes : null,
                'transferred_by' => $actor->id,
                'transferred_at' => $now,
            ]);

            $previousStatus = $ticket->status->name;

            $ticket->forceFill([
                'current_department_id' => $target->id,
                'accepted_by' => null,
                'accepted_at' => null,
                'status_id' => $initial->id,
                'resume_status_id' => null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record($ticket, 'ticket.department_transferred', 'Memindahkan ticket '.$ticket->number.' dari '.$from->name.' ke '.$target->name.' ('.$reason->name.')',
                old: ['department' => $from->name, 'status' => $previousStatus, 'assignees' => $ended->map(fn (TicketAssignment $assignment): string => $assignment->user->name)->all()],
                new: ['department' => $target->name, 'status' => $initial->name],
                properties: ['reason' => $reason->name, 'note' => filled($notes) ? $notes : null],
            );

            if ($previous->id !== $initial->id) {
                $this->workflow->record($ticket, null, $previous, $initial, $actor, $notes, ['event' => 'department_transferred', 'to_department' => $target->name]);
            }

            return $ticket;
        });
    }
}
