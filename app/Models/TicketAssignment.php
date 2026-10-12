<?php

namespace App\Models;

use App\Enums\TicketAssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ticket PIC or collaborator assignment with history (ended_at, never deleted).
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property TicketAssignmentRole $role
 * @property Carbon $assigned_at
 * @property int|null $assigned_by
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ticket_id', 'user_id', 'role', 'assigned_at', 'assigned_by'])]
class TicketAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TicketAssignmentRole::class,
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
