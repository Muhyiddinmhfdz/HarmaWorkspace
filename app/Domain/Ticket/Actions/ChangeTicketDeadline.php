<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for the ticket deadline (manual; no SLA yet).
 *
 * - No baseline yet: the date becomes planned = current, no reason needed.
 * - Baseline exists: only the current deadline changes, a reason is required
 *   and the change is stored in schedule_changes.
 *
 * Deadlines are dates; they are stored as the end of that day.
 */
class ChangeTicketDeadline
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function handle(Ticket $ticket, ?string $date, ?string $reason, User $actor): Ticket
    {
        $this->guard->ensureActive($ticket);

        $new = filled($date) ? Carbon::parse((string) $date)->endOfDay() : null;

        if ($ticket->current_due_at?->toDateString() === $new?->toDateString()) {
            throw new BusinessRuleException('Deadline baru sama dengan deadline saat ini.');
        }

        $hasBaseline = $ticket->planned_due_at !== null;

        if ($hasBaseline && blank($reason)) {
            throw new BusinessRuleException('Alasan perubahan deadline wajib diisi.');
        }

        return DB::transaction(function () use ($ticket, $new, $reason, $actor, $hasBaseline): Ticket {
            $old = $ticket->current_due_at;

            $ticket->scheduleChanges()->create([
                'old_start' => null,
                'old_end' => $old,
                'new_start' => null,
                'new_end' => $new,
                'reason' => filled($reason) ? (string) $reason : 'Deadline awal ditetapkan',
                'changed_by' => $actor->id,
            ]);

            $attributes = ['current_due_at' => $new, 'updated_by' => $actor->id];

            if (! $hasBaseline) {
                $attributes['planned_due_at'] = $new;
            }

            $ticket->forceFill($attributes)->save();

            $this->audit->record($ticket, 'ticket.deadline_changed',
                'Mengubah deadline ticket '.$ticket->number.': '.($old?->translatedFormat('d M Y') ?? 'belum ada').' → '.($new?->translatedFormat('d M Y') ?? 'tanpa deadline'),
                old: ['current_due_at' => $old?->toDateString()],
                new: ['current_due_at' => $new?->toDateString()],
                properties: ['reason' => $reason],
            );

            return $ticket;
        });
    }
}
