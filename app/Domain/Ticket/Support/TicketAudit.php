<?php

namespace App\Domain\Ticket\Support;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ticket\Events\TicketActivityRecorded;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Writes ticket activity (subject = ticket). Entries carry the project and
 * task id so significant events also appear on the project and task
 * timelines without duplicate rows. Comments are not activity (ADR-060).
 */
class TicketAudit
{
    /**
     * Events shown on the related project's timeline.
     *
     * @var list<string>
     */
    public const array PROJECT_EVENTS = ['ticket.created', 'ticket.resolved', 'ticket.reopened'];

    /**
     * Events shown on the related task's activity.
     *
     * @var list<string>
     */
    public const array TASK_EVENTS = ['ticket.created', 'ticket.resolved'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $properties
     */
    public function record(Ticket $ticket, string $event, string $description, array $old = [], array $new = [], array $properties = []): void
    {
        $properties = array_filter([
            'ticket_number' => $ticket->number,
            'project_id' => $ticket->project_id,
            'task_id' => $ticket->task_id,
            ...$properties,
        ], fn ($value) => $value !== null);

        $this->audit->record(
            event: $event,
            description: $description,
            subject: $ticket,
            old: $old,
            new: $new,
            properties: $properties,
        );

        $actorId = auth()->id();

        DB::afterCommit(fn () => TicketActivityRecorded::dispatch($ticket, $event, is_int($actorId) ? $actorId : null, $properties));
    }
}
