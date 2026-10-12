<?php

namespace App\Domain\Ticket\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after every recorded ticket activity (after the transaction
 * commits). No listeners yet: it is the hook for the Notification phase,
 * so ticket actions never call notification code directly.
 */
class TicketActivityRecorded
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public Ticket $ticket,
        public string $event,
        public ?int $actorId,
        public array $properties = [],
    ) {}
}
