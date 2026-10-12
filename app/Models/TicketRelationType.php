<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Relation between two tickets. `is_duplicate` marks the type used when a ticket is resolved as a duplicate.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_duplicate
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'is_duplicate', 'sort_order'])]
class TicketRelationType extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_duplicate' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return TicketRelation::query()->where('ticket_relation_type_id', $this->id)->exists();
    }
}
