<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ticket category; parent of subcategories.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'sort_order'])]
class TicketCategory extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TicketSubcategory, $this>
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(TicketSubcategory::class);
    }

    public function isInUse(): bool
    {
        return Ticket::query()->where('ticket_category_id', $this->id)->exists()
            || TicketSubcategory::query()->where('ticket_category_id', $this->id)->exists();
    }
}
