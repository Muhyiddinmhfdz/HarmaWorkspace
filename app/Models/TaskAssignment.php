<?php

namespace App\Models;

use App\Enums\TaskAssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PIC or collaborator assignment with history (ended_at, never deleted).
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property TaskAssignmentRole $role
 * @property Carbon $assigned_at
 * @property int|null $assigned_by
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['task_id', 'user_id', 'role', 'assigned_at', 'assigned_by'])]
class TaskAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TaskAssignmentRole::class,
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
