<?php

namespace App\Policies;

use App\Models\ApprovalTemplate;
use App\Models\User;

class ApprovalTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approval.manage-templates');
    }

    public function view(User $user, ApprovalTemplate $template): bool
    {
        return $user->can('approval.manage-templates');
    }

    public function manage(User $user, ?ApprovalTemplate $template = null): bool
    {
        return $user->can('approval.manage-templates');
    }
}
