<?php

namespace App\Domain\Workflow\Support;

use App\Enums\TaskAssignmentRole;
use App\Enums\TicketAssignmentRole;
use App\Enums\WorkflowEntity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The single place that knows which contextual roles a user holds on a
 * record (project manager, main PIC, requester, department leader, ...).
 * Workflow rules and approver resolution use it; views never re-implement it.
 */
class WorkflowActorResolver
{
    public const string PROJECT_MANAGER = 'project_manager';

    public const string PROJECT_OWNER = 'project_owner';

    public const string DEPARTMENT_LEADER = 'department_leader';

    public const string MEMBER = 'member';

    public const string CREATOR = 'creator';

    public const string STANDALONE_CREATOR = 'standalone_creator';

    public const string MAIN_PIC = 'main_pic';

    public const string COLLABORATOR = 'collaborator';

    public const string REQUESTER = 'requester';

    public const string REQUESTER_REVIEWER = 'requester_reviewer';

    /**
     * Roles an administrator can choose per entity, with friendly labels.
     *
     * @return array<string, string>
     */
    public function available(WorkflowEntity $entity): array
    {
        return match ($entity) {
            WorkflowEntity::Project => [
                self::PROJECT_MANAGER => 'Project Manager',
                self::PROJECT_OWNER => 'Project Owner',
                self::DEPARTMENT_LEADER => 'Leader of an involved department',
                self::MEMBER => 'Project member',
                self::CREATOR => 'Creator',
            ],
            WorkflowEntity::Task => [
                self::MAIN_PIC => 'Main PIC',
                self::COLLABORATOR => 'Collaborator',
                self::DEPARTMENT_LEADER => 'Leader of the task department',
                self::PROJECT_MANAGER => 'Project Manager (project task)',
                self::STANDALONE_CREATOR => 'Creator (standalone task)',
                self::CREATOR => 'Creator',
            ],
            WorkflowEntity::Ticket => [
                self::REQUESTER => 'Requester',
                self::REQUESTER_REVIEWER => 'Requester (not the Main PIC)',
                self::MAIN_PIC => 'Main PIC',
                self::COLLABORATOR => 'Collaborator',
                self::DEPARTMENT_LEADER => 'Leader of the current department',
                self::PROJECT_MANAGER => 'Project Manager (related project)',
                self::CREATOR => 'Creator',
            ],
        };
    }

    /**
     * Whether the user holds any of the roles on the record.
     *
     * @param  list<string>  $roles
     */
    public function holdsAny(User $user, Model $record, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->holds($user, $record, $role)) {
                return true;
            }
        }

        return false;
    }

    public function holds(User $user, Model $record, string $role): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match (true) {
            $record instanceof Project => $this->project($user, $record, $role),
            $record instanceof Task => $this->task($user, $record, $role),
            $record instanceof Ticket => $this->ticket($user, $record, $role),
            default => false,
        };
    }

    private function project(User $user, Project $project, string $role): bool
    {
        return match ($role) {
            self::PROJECT_MANAGER => $project->project_manager_id === $user->id,
            self::PROJECT_OWNER => $project->owner_id === $user->id,
            self::MEMBER => $project->members()->where('user_id', $user->id)->exists(),
            self::CREATOR => $project->created_by === $user->id,
            self::DEPARTMENT_LEADER => $project->departments()
                ->where(fn ($query) => $query
                    ->where('lead_user_id', $user->id)
                    ->orWhereIn('department_id', $user->activeMemberships()->where('is_leader', true)->select('department_id')))
                ->exists(),
            default => false,
        };
    }

    private function task(User $user, Task $task, string $role): bool
    {
        return match ($role) {
            self::MAIN_PIC => $task->activeAssignments()->where('user_id', $user->id)->where('role', TaskAssignmentRole::Pic->value)->exists(),
            self::COLLABORATOR => $task->activeAssignments()->where('user_id', $user->id)->where('role', TaskAssignmentRole::Collaborator->value)->exists(),
            self::DEPARTMENT_LEADER => $task->department_id !== null && $user->leadsDepartment($task->department_id),
            self::PROJECT_MANAGER => $task->project_id !== null && $task->loadMissing('project')->project?->project_manager_id === $user->id,
            self::STANDALONE_CREATOR => $task->project_id === null && $task->created_by === $user->id,
            self::CREATOR => $task->created_by === $user->id,
            default => false,
        };
    }

    private function ticket(User $user, Ticket $ticket, string $role): bool
    {
        $isPic = fn (): bool => $ticket->activeAssignments()->where('user_id', $user->id)->where('role', TicketAssignmentRole::Pic->value)->exists();

        return match ($role) {
            self::REQUESTER => $ticket->requester_user_id === $user->id || $ticket->created_by === $user->id,
            self::REQUESTER_REVIEWER => ($ticket->requester_user_id === $user->id || $ticket->created_by === $user->id) && ! $isPic(),
            self::MAIN_PIC => $isPic(),
            self::COLLABORATOR => $ticket->activeAssignments()->where('user_id', $user->id)->where('role', TicketAssignmentRole::Collaborator->value)->exists(),
            self::DEPARTMENT_LEADER => $user->leadsDepartment($ticket->current_department_id),
            self::PROJECT_MANAGER => $ticket->project_id !== null && $ticket->loadMissing('project')->project?->project_manager_id === $user->id,
            self::CREATOR => $ticket->created_by === $user->id,
            default => false,
        };
    }
}
