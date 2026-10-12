<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('department.view');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->can('department.view');
    }

    public function create(User $user): bool
    {
        return $user->can('department.create');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('department.update');
    }

    public function activate(User $user, Department $department): bool
    {
        return $user->can('department.activate');
    }

    public function deactivate(User $user, Department $department): bool
    {
        return $user->can('department.deactivate');
    }
}
