<?php

namespace App\Models;

use App\Enums\TaskAssignmentRole;
use App\Models\Concerns\HasVisibilityScope;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The main unit of work. Belongs to a project or stands alone.
 *
 * Sensitive attributes (number, project, status, department, schedule,
 * estimate/weight, progress, blocker, completion) are not mass assignable;
 * they change only through domain actions that record history.
 *
 * @property int $id
 * @property string $number
 * @property string $title
 * @property string|null $description
 * @property int|null $project_id
 * @property int|null $milestone_id
 * @property int|null $task_type_id
 * @property int|null $task_category_id
 * @property int $status_id
 * @property int|null $workflow_version_id
 * @property int $priority_id
 * @property int|null $department_id
 * @property Carbon|null $planned_start
 * @property Carbon|null $planned_end
 * @property Carbon|null $current_start
 * @property Carbon|null $current_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property string|null $estimated_hours
 * @property string|null $weight
 * @property int $progress_percentage
 * @property string|null $blocked_reason
 * @property Carbon|null $blocked_at
 * @property int|null $blocked_by
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'task_type_id', 'task_category_id', 'priority_id'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasVisibilityScope;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_start' => 'date',
            'planned_end' => 'date',
            'current_start' => 'date',
            'current_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'estimated_hours' => 'decimal:2',
            'weight' => 'decimal:2',
            'progress_percentage' => 'integer',
            'blocked_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<ProjectMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    /**
     * @return BelongsTo<TaskType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }

    /**
     * @return BelongsTo<TaskCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'task_category_id');
    }

    /**
     * The workflow version this record runs on (fixed at creation).
     *
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * @return BelongsTo<Priority, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * All assignments including ended ones (history).
     *
     * @return HasMany<TaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    /**
     * @return HasMany<TaskAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->whereNull('ended_at');
    }

    /**
     * @return HasOne<TaskAssignment, $this>
     */
    public function picAssignment(): HasOne
    {
        return $this->hasOne(TaskAssignment::class)->whereNull('ended_at')->where('role', TaskAssignmentRole::Pic->value);
    }

    /**
     * @return HasMany<TaskAssignment, $this>
     */
    public function collaboratorAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->whereNull('ended_at')->where('role', TaskAssignmentRole::Collaborator->value);
    }

    /**
     * @return HasMany<TaskSubtask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(TaskSubtask::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return MorphMany<ChecklistItem, $this>
     */
    public function checklistItems(): MorphMany
    {
        return $this->morphMany(ChecklistItem::class, 'checklistable')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return MorphMany<ScheduleChange, $this>
     */
    public function scheduleChanges(): MorphMany
    {
        return $this->morphMany(ScheduleChange::class, 'schedulable');
    }

    /**
     * Single source of truth for task visibility (lists, my tasks, detail,
     * dashboard, search, policies).
     *
     * - inactive users: nothing; `task.view-all`: everything;
     * - without `task.view`: nothing;
     * - project task: visible exactly when the project is visible
     *   (Project::scopeVisibleTo);
     * - standalone task: PIC, active collaborators, creator, and active
     *   members (incl. leaders) of the task department.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->can('task.view-all')) {
            return;
        }

        if (! $user->can('task.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $projectTasks) => $projectTasks
                ->whereNotNull('project_id')
                ->whereHas('project', fn (Builder $project) => $project->visibleTo($user)))
            ->orWhere(fn (Builder $standalone) => $standalone
                ->whereNull('project_id')
                ->where(fn (Builder $standalone) => $standalone
                    ->where('created_by', $user->id)
                    ->orWhereHas('activeAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id))
                    ->orWhereIn('department_id', DepartmentMembership::query()
                        ->select('department_id')
                        ->where('user_id', $user->id)
                        ->whereNull('ended_at')))));
    }

    /**
     * Same rule as scopeVisibleTo() with query-free shortcuts.
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->can('task.view-all')) {
            return true;
        }

        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Tasks the user is assigned to (PIC or active collaborator).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, User $user, ?TaskAssignmentRole $role = null): void
    {
        $query->whereHas('activeAssignments', fn (Builder $assignments) => $assignments
            ->where('user_id', $user->id)
            ->when($role !== null, fn (Builder $assignments) => $assignments->where('role', $role?->value)));
    }

    /**
     * Not in a terminal status (still to be worked on).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->where('is_terminal', false));
    }

    /**
     * Open tasks whose current deadline has passed.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->open()->whereNotNull('current_end')->whereDate('current_end', '<', Date::today());
    }

    /**
     * Work has started and is moving: started, not blocked, not finished.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inProgress(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->where('counts_as_started', true)->where('is_blocked', false)->where('is_terminal', false));
    }

    /**
     * Open work that has not started yet (to do).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notStarted(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->where('counts_as_started', false)->where('is_blocked', false)->where('is_terminal', false));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function blocked(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->where('is_blocked', true));
    }

    public function isStandalone(): bool
    {
        return $this->project_id === null;
    }

    public function isOverdue(): bool
    {
        return $this->current_end !== null
            && ! $this->status->is_terminal
            && $this->current_end->lt(Date::today());
    }
}
