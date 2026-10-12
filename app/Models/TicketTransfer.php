<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a ticket moving between departments.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $from_department_id
 * @property int $to_department_id
 * @property int $transfer_reason_id
 * @property string|null $notes
 * @property int $transferred_by
 * @property Carbon $transferred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ticket_id', 'from_department_id', 'to_department_id', 'transfer_reason_id', 'notes', 'transferred_by', 'transferred_at'])]
class TicketTransfer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * @return BelongsTo<TicketTransferReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(TicketTransferReason::class, 'transfer_reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transferrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
