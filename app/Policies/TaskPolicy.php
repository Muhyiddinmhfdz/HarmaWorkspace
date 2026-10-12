<?php

namespace App\Policies;

use App\Enums\TaskAssignmentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Task authorization = visibility (Task::scopeVisibleTo) + global permission
 * or a contextual role (ADR-050):
 *
 * - managers: project manager (project tasks), leader of the task department,
 *   creator (standalone tasks) → manage the whole task;
 * - Main PIC → work on it (progress, non-terminal status changes, subtasks,
 *   checklist);
 * - collaborators → participate (subtasks, checklist).
 *
 * Tasks of archived or finished projects are read-only for everyone.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('task.view') || $user->can('task.view-all');
    }

    public function view(User $user, Task $task): bool
    {
        return $task->isVisibleTo($user);
    }

    /**
     * Standalone: task.create. Project task: the project must accept work and
     * the user must manage it, lead one of its departments, or hold
     * task.create while being an active member (or seeing all projects).
     */
    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return $user->can('task.create');
        }

        $project->loadMissing('status');

        if ($project->isArchived() || $project->status->is_terminal || ! $project->isVisibleTo($user)) {
            return false;
        }

        if ($project->isManagedBy($user) || $this->leadsProjectDepartment($user, $project)) {
            return true;
        }

        return $user->can('task.create')
            && ($user->can('project.view-all') || $project->members()->where('user_id', $user->id)->exists());
    }

    public function update(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user) && ($this->isManager($user, $task) || $user->can('task.update'));
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user) && ($this->isManager($user, $task) || $user->can('task.assign'));
    }

    public function manageSchedule(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user) && ($this->isManager($user, $task) || $user->can('task.manage-schedule'));
    }

    /**
     * Progress and non-terminal status changes.
     */
    public function work(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user)
            && ($this->isPic($user, $task) || $this->isManager($user, $task) || $user->can('task.manage-progress'));
    }

    /**
     * Subtasks and checklist.
     */
    public function participate(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user)
            && ($this->isAssignee($user, $task) || $this->isManager($user, $task) || $user->can('task.update'));
    }

    /**
     * Completing (and reopening finished tasks).
     */
    public function complete(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user) && ($this->isManager($user, $task) || $user->can('task.complete'));
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->writable($task) && $task->isVisibleTo($user) && ($this->isManager($user, $task) || $user->can('task.cancel'));
    }

    public function isManager(User $user, Task $task): bool
    {
        if ($task->department_id !== null && $user->leadsDepartment($task->department_id)) {
            return true;
        }

        if ($task->project_id !== null) {
            return $task->loadMissing('project')->project?->isManagedBy($user) ?? false;
        }

        return $task->created_by === $user->id;
    }

    private function isPic(User $user, Task $task): bool
    {
        return $task->activeAssignments()->where('user_id', $user->id)->where('role', TaskAssignmentRole::Pic->value)->exists();
    }

    private function isAssignee(User $user, Task $task): bool
    {
        return $task->activeAssignments()->where('user_id', $user->id)->exists();
    }

    private function writable(Task $task): bool
    {
        if ($task->project_id === null) {
            return true;
        }

        $project = $task->loadMissing('project.status')->project;

        return $project !== null && ! $project->isArchived() && ! $project->status->is_terminal;
    }

    private function leadsProjectDepartment(User $user, Project $project): bool
    {
        return $project->departments()
            ->where(fn ($query) => $query
                ->where('lead_user_id', $user->id)
                ->orWhereIn('department_id', $user->activeMemberships()->where('is_leader', true)->select('department_id')))
            ->exists();
    }
}
