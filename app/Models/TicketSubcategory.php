<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Subcategory, always bound to one ticket category.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $ticket_category_id
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'ticket_category_id', 'sort_order'])]
class TicketSubcategory extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_category_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function isInUse(): bool
    {
        return Ticket::query()->where('ticket_subcategory_id', $this->id)->exists();
    }
}
