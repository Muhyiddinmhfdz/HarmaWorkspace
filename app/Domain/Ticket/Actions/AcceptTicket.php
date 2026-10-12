<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The current department takes the ticket into its queue (ADR-053).
 * Acceptance is not assignment: the ticket moves to "Unassigned" until a
 * PIC is chosen.
 */
class AcceptTicket
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function handle(Ticket $ticket, User $actor): Ticket
    {
        $this->guard->ensureActive($ticket);

        return DB::transaction(function () use ($ticket, $actor): Ticket {
            $ticket = Ticket::query()->with(['status', 'acceptor:id,name', 'currentDepartment:id,name'])->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->accepted_at !== null) {
                throw new BusinessRuleException('Laporan sudah diterima oleh '.($ticket->acceptor->name ?? 'pengguna lain').'. Muat ulang data terbaru.');
            }

            $this->guard->ensureActive($ticket);

            $ticket->forceFill(['accepted_by' => $actor->id, 'accepted_at' => Date::now(), 'updated_by' => $actor->id])->save();

            $this->audit->record($ticket, 'ticket.accepted', $ticket->currentDepartment->name.' menerima ticket '.$ticket->number, new: ['department' => $ticket->currentDepartment->name]);

            return $ticket;
        });
    }
}
