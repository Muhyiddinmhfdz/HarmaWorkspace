<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only schedule history row.
 *
 * @property int $id
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property Carbon|null $old_start
 * @property Carbon|null $old_end
 * @property Carbon|null $new_start
 * @property Carbon|null $new_end
 * @property string $reason
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 */
#[Fillable(['old_start', 'old_end', 'new_start', 'new_end', 'reason', 'changed_by'])]
class ScheduleChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_start' => 'date',
            'old_end' => 'date',
            'new_start' => 'date',
            'new_end' => 'date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
