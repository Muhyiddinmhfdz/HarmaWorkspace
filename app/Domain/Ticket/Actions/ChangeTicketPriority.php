<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Enums\MasterScope;
use App\Models\Ticket;
use App\Models\User;

/**
 * Priority is decided by the handling department, not by the requester.
 */
class ChangeTicketPriority
{
    public function __construct(
        private ScopedMasterGuard $masters,
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function handle(Ticket $ticket, int $priorityId, User $actor): Ticket
    {
        $this->guard->ensureActive($ticket);
        $priority = $this->masters->priority($priorityId, MasterScope::Ticket);
        $ticket->loadMissing('priority');

        if ($ticket->priority_id === $priority->id) {
            return $ticket;
        }

        $old = $ticket->priority->name;
        $ticket->forceFill(['priority_id' => $priority->id, 'updated_by' => $actor->id])->save();

        $this->audit->record($ticket, 'ticket.priority_changed', 'Mengubah priority ticket: '.$old.' → '.$priority->name, old: ['priority' => $old], new: ['priority' => $priority->name]);

        return $ticket->setRelation('priority', $priority);
    }
}
