<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A directed link between two tickets (e.g. "duplicate of").
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $related_ticket_id
 * @property int $ticket_relation_type_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ticket_id', 'related_ticket_id', 'ticket_relation_type_id', 'created_by'])]
class TicketRelation extends Model
{
    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function relatedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'related_ticket_id');
    }

    /**
     * @return BelongsTo<TicketRelationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketRelationType::class, 'ticket_relation_type_id');
    }
}
