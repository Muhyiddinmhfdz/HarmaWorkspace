<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Lightweight checklist item of a task or a subtask.
 *
 * @property int $id
 * @property string $checklistable_type
 * @property int $checklistable_id
 * @property string $text
 * @property bool $is_completed
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['text'])]
class ChecklistItem extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_completed' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function checklistable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
