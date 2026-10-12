<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSource;
use App\Models\TicketType;
use App\Models\User;

/**
 * General ticket information (title, description, type, category,
 * subcategory, source, client). Status, assignment, department, deadline,
 * priority and resolution have their own actions. A category change
 * re-validates the subcategory.
 */
class UpdateTicket
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    /**
     * @param  array{title: string, description: string, ticket_type_id: int, ticket_category_id: int, ticket_subcategory_id?: int|null, ticket_source_id?: int|null, client_id?: int|null}  $data
     */
    public function handle(Ticket $ticket, array $data, User $actor): Ticket
    {
        $this->guard->ensureNotFinal($ticket);

        $categoryId = (int) $data['ticket_category_id'];
        $this->guard->master(TicketType::class, (int) $data['ticket_type_id'], 'Ticket type', $ticket->ticket_type_id, required: true);
        $this->guard->master(TicketCategory::class, $categoryId, 'Category', $ticket->ticket_category_id, required: true);
        $subcategory = $this->guard->subcategory($this->id($data['ticket_subcategory_id'] ?? null), $categoryId, $ticket->ticket_subcategory_id);
        $this->guard->master(TicketSource::class, $this->id($data['ticket_source_id'] ?? null), 'Source', $ticket->ticket_source_id);
        $this->guard->master(Client::class, $this->id($data['client_id'] ?? null), 'Client', $ticket->client_id);

        $ticket->fill([
            'title' => $data['title'],
            'description' => $data['description'],
            'ticket_type_id' => (int) $data['ticket_type_id'],
            'ticket_category_id' => $categoryId,
            'ticket_subcategory_id' => $subcategory?->id,
            'ticket_source_id' => $this->id($data['ticket_source_id'] ?? null),
            'client_id' => $this->id($data['client_id'] ?? null),
        ]);

        if (! $ticket->isDirty()) {
            return $ticket;
        }

        $old = $ticket->getOriginal();
        $changes = $ticket->getDirty();
        $ticket->forceFill(['updated_by' => $actor->id])->save();

        $this->audit->record($ticket, 'ticket.updated', 'Mengubah informasi ticket '.$ticket->number,
            old: array_intersect_key($old, $changes),
            new: $changes,
        );

        return $ticket;
    }

    private function id(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }
}
