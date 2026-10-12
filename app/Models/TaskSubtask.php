<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A smaller piece of a task (one level only). Done / not done; it does not
 * drive the task progress in Phase 3 (ADR-043).
 *
 * @property int $id
 * @property int $task_id
 * @property string $title
 * @property string|null $description
 * @property int|null $pic_user_id
 * @property Carbon|null $due_date
 * @property bool $is_completed
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'pic_user_id', 'due_date'])]
class TaskSubtask extends Model
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
            'due_date' => 'date',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    /**
     * @return MorphMany<ChecklistItem, $this>
     */
    public function checklistItems(): MorphMany
    {
        return $this->morphMany(ChecklistItem::class, 'checklistable')->orderBy('sort_order')->orderBy('id');
    }
}
