<?php

namespace App\Policies;

use App\Models\User;

/**
 * Permission + resource scope for user administration. Business invariants
 * (no self-deactivation, last Super Admin, role escalation) live in the
 * domain actions so they also hold for the Super Admin bypass.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('user.view');
    }

    public function view(User $user, User $model): bool
    {
        return $model->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('user.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('user.update');
    }

    public function activate(User $user, User $model): bool
    {
        return $user->can('user.activate');
    }

    public function deactivate(User $user, User $model): bool
    {
        return $user->can('user.deactivate') && ! $user->is($model);
    }
}
