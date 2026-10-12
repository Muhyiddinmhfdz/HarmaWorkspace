<?php

namespace App\Models;

use App\Enums\TicketAssignmentRole;
use App\Models\Concerns\HasVisibilityScope;
use Database\Factories\TicketFactory;
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
 * A request handled by exactly one current department (Department Inbox).
 * Standalone, or related to a project and/or task.
 *
 * Only descriptive fields are mass assignable. Requester, department,
 * status, assignment, acceptance, deadline, resolution and closure change
 * only through domain actions that record history.
 *
 * @property int $id
 * @property string $number
 * @property string $title
 * @property string $description
 * @property int $requester_user_id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int $ticket_type_id
 * @property int $ticket_category_id
 * @property int|null $ticket_subcategory_id
 * @property int|null $ticket_source_id
 * @property int $status_id
 * @property int $priority_id
 * @property int $current_department_id
 * @property int|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $planned_due_at
 * @property Carbon|null $current_due_at
 * @property Carbon|null $first_assigned_at
 * @property Carbon|null $started_at
 * @property int|null $resume_status_id
 * @property int|null $resolution_id
 * @property string|null $resolution_notes
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property int|null $rejection_reason_id
 * @property string|null $rejection_notes
 * @property int $reopen_count
 * @property Carbon|null $last_reopened_at
 * @property int $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'ticket_type_id', 'ticket_category_id', 'ticket_subcategory_id', 'ticket_source_id', 'client_id'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasVisibilityScope;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'planned_due_at' => 'datetime',
            'current_due_at' => 'datetime',
            'first_assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_reopened_at' => 'datetime',
            'reopen_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    /**
     * @return BelongsTo<TicketSubcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(TicketSubcategory::class, 'ticket_subcategory_id');
    }

    /**
     * @return BelongsTo<TicketSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TicketSource::class, 'ticket_source_id');
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Status to return to after "Need Information".
     *
     * @return BelongsTo<Status, $this>
     */
    public function resumeStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'resume_status_id');
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
    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @return BelongsTo<TicketResolution, $this>
     */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(TicketResolution::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<TicketRejectionReason, $this>
     */
    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(TicketRejectionReason::class);
    }

    /**
     * All assignments including ended ones (history).
     *
     * @return HasMany<TicketAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class);
    }

    /**
     * @return HasMany<TicketAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->whereNull('ended_at');
    }

    /**
     * @return HasOne<TicketAssignment, $this>
     */
    public function picAssignment(): HasOne
    {
        return $this->hasOne(TicketAssignment::class)->whereNull('ended_at')->where('role', TicketAssignmentRole::Pic->value);
    }

    /**
     * @return HasMany<TicketAssignment, $this>
     */
    public function collaboratorAssignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->whereNull('ended_at')->where('role', TicketAssignmentRole::Collaborator->value);
    }

    /**
     * @return HasMany<TicketTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(TicketTransfer::class)->orderBy('transferred_at')->orderBy('id');
    }

    /**
     * Relations created from this ticket.
     *
     * @return HasMany<TicketRelation, $this>
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(TicketRelation::class);
    }

    /**
     * Relations pointing to this ticket.
     *
     * @return HasMany<TicketRelation, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(TicketRelation::class, 'related_ticket_id');
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return MorphMany<ScheduleChange, $this>
     */
    public function scheduleChanges(): MorphMany
    {
        return $this->morphMany(ScheduleChange::class, 'schedulable');
    }

    /**
     * Single source of truth for ticket visibility (lists, inbox, my tickets,
     * detail, dashboard, search, comments, attachments, policies). Ticket
     * visibility is independent from project visibility (ADR-058).
     *
     * - inactive users: nothing; `ticket.view-all`: everything;
     * - without `ticket.view`: nothing;
     * - otherwise: requester, creator, active PIC/collaborators, active members
     *   (incl. leaders) of the CURRENT department, and the project manager of
     *   the related project. Former departments keep no access after a
     *   transfer (ADR-055).
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->can('ticket.view-all')) {
            return;
        }

        if (! $user->can('ticket.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $this->getTable();

        $query->where(fn (Builder $query) => $query
            ->where($table.'.requester_user_id', $user->id)
            ->orWhere($table.'.created_by', $user->id)
            ->orWhereHas('activeAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id))
            ->orWhereIn($table.'.current_department_id', DepartmentMembership::query()
                ->select('department_id')
                ->where('user_id', $user->id)
                ->whereNull('ended_at'))
            ->orWhereIn($table.'.project_id', Project::query()
                ->select('id')
                ->where('project_manager_id', $user->id)));
    }

    /**
     * Same rule as scopeVisibleTo() with query-free shortcuts.
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->can('ticket.view-all')) {
            return true;
        }

        if ($user->can('ticket.view') && ($this->requester_user_id === $user->id || $this->created_by === $user->id)) {
            return true;
        }

        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Tickets the user is assigned to (PIC or active collaborator).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, User $user, ?TicketAssignmentRole $role = null): void
    {
        $query->whereHas('activeAssignments', fn (Builder $assignments) => $assignments
            ->where('user_id', $user->id)
            ->when($role !== null, fn (Builder $assignments) => $assignments->where('role', $role?->value)));
    }

    /**
     * Still active: neither resolved/closed (completed) nor terminal.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->where('is_terminal', false)->where('counts_as_completed', false));
    }

    /**
     * Active tickets whose current deadline has passed.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->open()->whereNotNull($this->qualifyColumn('current_due_at'))->where($this->qualifyColumn('current_due_at'), '<', Date::now());
    }

    public function isStandalone(): bool
    {
        return $this->project_id === null && $this->task_id === null;
    }

    /**
     * Requires the status relation to be loaded.
     */
    public function isActive(): bool
    {
        return ! $this->status->is_terminal && ! $this->status->counts_as_completed;
    }

    public function isOverdue(): bool
    {
        return $this->current_due_at !== null && $this->isActive() && $this->current_due_at->lt(Date::now());
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
