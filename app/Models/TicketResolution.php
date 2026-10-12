<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How a ticket was resolved. `requires_related_ticket` (e.g. Duplicate) makes the resolver link the primary ticket.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property bool $requires_related_ticket
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'color', 'requires_related_ticket', 'sort_order'])]
class TicketResolution extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_related_ticket' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return Ticket::query()->where('resolution_id', $this->id)->exists();
    }
}
