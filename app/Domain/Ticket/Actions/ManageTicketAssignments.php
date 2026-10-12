<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Enums\TicketAssignmentRole;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * One active Main PIC plus collaborators from the current department, with
 * full history (ADR-054). The row lock plus the unique "active PIC" index
 * mean two leaders can never create two active PICs.
 */
class ManageTicketAssignments
{
    public function __construct(
        private TicketGuard $guard,
        private WorkflowGate $workflow,
        private TicketAudit $audit,
    ) {}

    /**
     * First assignment from the inbox. Also accepts the ticket when the
     * department had not accepted it yet, and moves a queued ticket to the
     * assigned status. Fails when someone else assigned it meanwhile.
     */
    public function assign(Ticket $ticket, int $userId, User $actor): Ticket
    {
        return $this->write($ticket, $userId, $actor, expectPic: false);
    }

    /**
     * Replace the active PIC; the previous assignment is ended, not deleted.
     */
    public function changePic(Ticket $ticket, int $userId, User $actor): Ticket
    {
        return $this->write($ticket, $userId, $actor, expectPic: true);
    }

    public function addCollaborator(Ticket $ticket, int $userId, User $actor): TicketAssignment
    {
        $this->guard->ensureActive($ticket);

        return DB::transaction(function () use ($ticket, $userId, $actor): TicketAssignment {
            $ticket = Ticket::query()->with(['status', 'currentDepartment'])->lockForUpdate()->findOrFail($ticket->id);
            $user = $this->guard->departmentMember($userId, $ticket->currentDepartment);

            $existing = $ticket->activeAssignments()->where('user_id', $user->id)->first();

            if ($existing !== null) {
                throw new BusinessRuleException($existing->role === TicketAssignmentRole::Pic
                    ? $user->name.' sudah menjadi PIC ticket ini.'
                    : $user->name.' sudah menjadi kolaborator ticket ini.');
            }

            $assignment = TicketAssignment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'role' => TicketAssignmentRole::Collaborator,
                'assigned_at' => Date::now(),
                'assigned_by' => $actor->id,
            ]);

            $this->audit->record($ticket, 'ticket.collaborator_added', 'Menambahkan '.$user->name.' sebagai kolaborator', new: ['collaborator' => $user->name]);

            return $assignment;
        });
    }

    public function removeCollaborator(Ticket $ticket, int $assignmentId, User $actor): void
    {
        $this->guard->ensureActive($ticket);

        $assignment = $ticket->collaboratorAssignments()->with('user:id,name')->findOrFail($assignmentId);

        DB::transaction(function () use ($ticket, $assignment, $actor): void {
            $assignment->forceFill(['ended_at' => Date::now(), 'ended_by' => $actor->id])->save();

            $this->audit->record($ticket, 'ticket.collaborator_removed', 'Mengeluarkan '.$assignment->user->name.' dari kolaborator', old: ['collaborator' => $assignment->user->name]);
        });
    }

    private function write(Ticket $ticket, int $userId, User $actor, bool $expectPic): Ticket
    {
        $this->guard->ensureActive($ticket);

        return DB::transaction(function () use ($ticket, $userId, $actor, $expectPic): Ticket {
            $ticket = Ticket::query()->with(['status', 'resumeStatus', 'currentDepartment'])->lockForUpdate()->findOrFail($ticket->id);
            $this->guard->ensureActive($ticket);
            $user = $this->guard->departmentMember($userId, $ticket->currentDepartment);

            $current = $ticket->picAssignment()->with('user:id,name')->first();

            if (! $expectPic && $current !== null) {
                throw new BusinessRuleException('Ticket sudah ditugaskan ke '.$current->user->name.' oleh pengguna lain. Muat ulang data terbaru.');
            }

            if ($expectPic && $current === null) {
                throw new BusinessRuleException('Ticket belum memiliki PIC. Muat ulang data terbaru.');
            }

            if ($current?->user_id === $user->id) {
                throw new BusinessRuleException($user->name.' sudah menjadi PIC ticket ini.');
            }

            $now = Date::now();
            $current?->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save();

            // A collaborator promoted to PIC leaves the collaborator role.
            $ticket->collaboratorAssignments()->where('user_id', $user->id)->get()
                ->each(fn (TicketAssignment $assignment): bool => $assignment->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save());

            TicketAssignment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'role' => TicketAssignmentRole::Pic,
                'assigned_at' => $now,
                'assigned_by' => $actor->id,
            ]);

            $attributes = ['updated_by' => $actor->id];
            $from = $ticket->status;

            if ($ticket->accepted_at === null) {
                $attributes += ['accepted_by' => $actor->id, 'accepted_at' => $now];
            }

            if ($ticket->first_assigned_at === null) {
                $attributes['first_assigned_at'] = $now;
            }

            $assignTransition = $this->workflow->find($ticket, $from, 'assign');
            $to = $assignTransition?->toState->status;

            if ($to !== null) {
                $attributes['status_id'] = $to->id;
            }

            $ticket->forceFill($attributes)->save();

            if ($assignTransition !== null && $to !== null) {
                $this->workflow->record($ticket, $assignTransition, $from, $to, $actor, null, ['pic' => $user->name]);
            }

            $this->audit->record(
                $ticket,
                $current ? 'ticket.pic_changed' : 'ticket.assigned',
                $current ? 'Mengganti PIC ticket: '.$current->user->name.' → '.$user->name : 'Menugaskan ticket ke '.$user->name,
                old: array_filter(['pic' => $current?->user->name, 'status' => $to !== null ? $from->name : null]),
                new: array_filter(['pic' => $user->name, 'status' => $to?->name]),
            );

            return $ticket;
        });
    }
}
