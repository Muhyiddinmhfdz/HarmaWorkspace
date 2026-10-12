<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Important project target (not a task). Its state is derived from dates:
 * completed when `actual_date` is set, otherwise upcoming or overdue
 * against `current_due_date`.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $description
 * @property int|null $owner_id
 * @property bool $is_key_milestone
 * @property Carbon $planned_date
 * @property Carbon $current_due_date
 * @property Carbon|null $actual_date
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'owner_id', 'is_key_milestone', 'sort_order'])]
class ProjectMilestone extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_key_milestone' => 'boolean',
            'planned_date' => 'date',
            'current_due_date' => 'date',
            'actual_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return MorphMany<ScheduleChange, $this>
     */
    public function scheduleChanges(): MorphMany
    {
        return $this->morphMany(ScheduleChange::class, 'schedulable');
    }

    public function isCompleted(): bool
    {
        return $this->actual_date !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->current_due_date->lt(today());
    }
}
