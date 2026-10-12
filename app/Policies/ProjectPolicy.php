<?php

namespace App\Policies;

use App\Domain\Project\Support\VisibilityGuard;
use App\Enums\ProjectVisibility;
use App\Models\Project;
use App\Models\User;

/**
 * Project authorization = visibility (Project::scopeVisibleTo) + either the
 * global permission or the contextual Project Manager role.
 *
 * Contextual rights cover day-to-day management only. Administrative actions
 * (archive, company-wide visibility, master data) always need a global
 * permission. The owner sees the project but manages it only with global
 * permissions (ADR-038). Project Manager is never a Spatie role (ADR-022).
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('project.view') || $user->can('project.view-all');
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('project.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.update');
    }

    public function manageDepartments(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.manage-departments');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.manage-members');
    }

    public function manageSchedule(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.manage-schedule');
    }

    public function manageMilestones(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.manage-milestones');
    }

    public function manageProgress(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.manage-progress');
    }

    public function complete(User $user, Project $project): bool
    {
        return $this->manages($user, $project, 'project.complete');
    }

    public function archive(User $user, Project $project): bool
    {
        return $user->can('project.archive') && $project->isVisibleTo($user);
    }

    /**
     * Restricted ↔ Department follows normal management rights; Company also
     * needs project.manage-visibility.
     */
    public function changeVisibility(User $user, Project $project, ?ProjectVisibility $target = null): bool
    {
        if ($target !== null && ! app(VisibilityGuard::class)->canSet($target, $user)) {
            return false;
        }

        return $this->manages($user, $project, 'project.update');
    }

    private function manages(User $user, Project $project, string $permission): bool
    {
        if ($project->isManagedBy($user)) {
            return true;
        }

        return $user->can($permission) && $project->isVisibleTo($user);
    }
}
