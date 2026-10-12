<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Ticket;
use App\Models\TicketRelation;
use App\Models\TicketRelationType;
use App\Models\User;

/**
 * Basic ticket ↔ ticket relations: never to itself, never twice for the
 * same pair (in either direction), only to tickets the actor can see.
 */
class ManageTicketRelations
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function add(Ticket $ticket, int $relatedTicketId, ?int $typeId, User $actor): TicketRelation
    {
        $this->guard->ensureNotFinal($ticket);
        /** @var TicketRelationType $type */
        $type = $this->guard->master(TicketRelationType::class, $typeId, 'Relation type', required: true);
        $related = Ticket::query()->find($relatedTicketId);

        if ($related === null || ! $related->isVisibleTo($actor)) {
            throw new BusinessRuleException('Laporan yang dipilih tidak ditemukan atau tidak dapat Anda akses.');
        }

        if ($related->id === $ticket->id) {
            throw new BusinessRuleException('Laporan tidak dapat dihubungkan dengan dirinya sendiri.');
        }

        $exists = TicketRelation::query()
            ->where(fn ($query) => $query->where('ticket_id', $ticket->id)->where('related_ticket_id', $related->id))
            ->orWhere(fn ($query) => $query->where('ticket_id', $related->id)->where('related_ticket_id', $ticket->id))
            ->exists();

        if ($exists) {
            throw new BusinessRuleException('Laporan '.$related->number.' sudah terhubung dengan laporan ini.');
        }

        $relation = TicketRelation::query()->create([
            'ticket_id' => $ticket->id,
            'related_ticket_id' => $related->id,
            'ticket_relation_type_id' => $type->id,
            'created_by' => $actor->id,
        ]);

        $this->audit->record($ticket, 'ticket.relation_added', 'Menghubungkan ticket dengan '.$related->number.' ('.$type->name.')', new: ['related_ticket' => $related->number, 'type' => $type->name]);

        return $relation;
    }

    public function remove(Ticket $ticket, int $relationId, User $actor): void
    {
        $this->guard->ensureNotFinal($ticket);

        $relation = TicketRelation::query()
            ->with('relatedTicket:id,number', 'ticket:id,number')
            ->where(fn ($query) => $query->where('ticket_id', $ticket->id)->orWhere('related_ticket_id', $ticket->id))
            ->findOrFail($relationId);

        $other = $relation->ticket_id === $ticket->id ? $relation->relatedTicket : $relation->ticket;
        $relation->delete();

        $this->audit->record($ticket, 'ticket.relation_removed', 'Melepas hubungan ticket dengan '.$other->number, old: ['related_ticket' => $other->number]);
    }
}
