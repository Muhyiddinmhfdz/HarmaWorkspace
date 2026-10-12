<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

/**
 * Workflow configuration is an administrative task (ADR-075).
 */
class WorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workflow.view') || $user->can('workflow.manage');
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user, ?Workflow $workflow = null): bool
    {
        return $user->can('workflow.manage');
    }

    public function publish(User $user, Workflow $workflow): bool
    {
        return $user->can('workflow.publish');
    }
}
