<?php

namespace App\Models;

use App\Enums\ProgressMode;
use App\Enums\ProjectVisibility;
use App\Models\Concerns\HasVisibilityScope;
use Database\Factories\ProjectFactory;
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

/**
 * A multi-division project.
 *
 * Sensitive attributes (number, status, health, schedule, progress, owner,
 * project manager, lifecycle timestamps) are not mass assignable: they change
 * only through dedicated domain actions that record history.
 *
 * @property int $id
 * @property string $number
 * @property string $name
 * @property string|null $description
 * @property int|null $client_id
 * @property int $project_category_id
 * @property int $status_id
 * @property int|null $workflow_version_id
 * @property int $priority_id
 * @property int $health_status_id
 * @property int|null $owner_id
 * @property int $project_manager_id
 * @property ProjectVisibility $visibility
 * @property Carbon $planned_start
 * @property Carbon $planned_end
 * @property Carbon $current_start
 * @property Carbon $current_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property ProgressMode $progress_mode
 * @property int $progress_percentage
 * @property Carbon|null $completed_at
 * @property Carbon|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'client_id', 'project_category_id', 'priority_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasVisibilityScope;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ProjectVisibility::class,
            'progress_mode' => ProgressMode::class,
            'progress_percentage' => 'integer',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'current_start' => 'date',
            'current_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<ProjectCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'project_category_id');
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
     * @return BelongsTo<ProjectHealthStatus, $this>
     */
    public function health(): BelongsTo
    {
        return $this->belongsTo(ProjectHealthStatus::class, 'health_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    /**
     * Departments currently involved (lead + supporting).
     *
     * @return HasMany<ProjectDepartment, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(ProjectDepartment::class)->whereNull('removed_at');
    }

    /**
     * @return HasOne<ProjectDepartment, $this>
     */
    public function leadDepartment(): HasOne
    {
        return $this->hasOne(ProjectDepartment::class)->whereNull('removed_at')->where('is_lead', true);
    }

    /**
     * Active members.
     *
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class)->whereNull('ended_at');
    }

    /**
     * All memberships including ended ones (history).
     *
     * @return HasMany<ProjectMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return HasMany<ProjectMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    /**
     * @return MorphMany<ScheduleChange, $this>
     */
    public function scheduleChanges(): MorphMany
    {
        return $this->morphMany(ScheduleChange::class, 'schedulable');
    }

    /**
     * Single source of truth for project visibility (lists, counts, detail,
     * dashboard, search). Visibility only controls VIEW.
     *
     * - inactive users: nothing;
     * - `project.view-all`: every project;
     * - without `project.view`: nothing;
     * - restricted (all modes): owner, project manager, active members, the
     *   department lead (PIC) of an involved department, and leaders of
     *   involved departments;
     * - department: + active members of any involved department;
     * - company: + every user holding `project.view`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->can('project.view-all')) {
            return;
        }

        if (! $user->can('project.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $memberOf = fn (bool $leadersOnly) => DepartmentMembership::query()
            ->select('department_id')
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->when($leadersOnly, fn (Builder $memberships) => $memberships->where('is_leader', true));

        $query->where(fn (Builder $query) => $query
            ->where('visibility', ProjectVisibility::Company->value)
            ->orWhere('owner_id', $user->id)
            ->orWhere('project_manager_id', $user->id)
            ->orWhereHas('members', fn (Builder $members) => $members->where('user_id', $user->id))
            ->orWhereHas('departments', fn (Builder $departments) => $departments->where(
                fn (Builder $departments) => $departments
                    ->where('lead_user_id', $user->id)
                    ->orWhereIn('department_id', $memberOf(true))
            ))
            ->orWhere(fn (Builder $query) => $query
                ->where('visibility', ProjectVisibility::Department->value)
                ->whereHas('departments', fn (Builder $departments) => $departments->whereIn('department_id', $memberOf(false)))));
    }

    /**
     * Projects the user personally takes part in (owner, manager or member).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function involving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('owner_id', $user->id)
            ->orWhere('project_manager_id', $user->id)
            ->orWhereHas('members', fn (Builder $members) => $members->where('user_id', $user->id)));
    }

    /**
     * Not archived and not in a terminal status.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'))
            ->whereHas('status', fn (Builder $status) => $status->where('is_terminal', false));
    }

    /**
     * Same rule as scopeVisibleTo(), with query-free shortcuts for the common
     * cases so per-row policy checks in lists do not cause N+1 queries.
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->can('project.view-all')) {
            return true;
        }

        if ($user->can('project.view') && ($this->isManagedBy($user) || $this->isOwnedBy($user) || $this->visibility === ProjectVisibility::Company)) {
            return true;
        }

        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * The project manager holds contextual management rights on this project.
     */
    public function isManagedBy(User $user): bool
    {
        return $user->id === $this->project_manager_id;
    }

    /**
     * The owner is the business sponsor: always sees the project, but does
     * not get management rights from ownership alone (ADR-038).
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id !== null && $user->id === $this->owner_id;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
