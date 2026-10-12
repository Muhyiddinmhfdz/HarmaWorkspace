<?php

namespace App\Domain\Access\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Shared escalation checks for role management.
 */
class RoleGuard
{
    public function isSuperAdminRole(Role $role): bool
    {
        return $role->name === config('harma.super_admin_role');
    }

    public function ensureEditable(Role $role): void
    {
        if ($this->isSuperAdminRole($role)) {
            throw new BusinessRuleException('Role Super Admin dikelola oleh sistem dan tidak dapat diubah.');
        }
    }

    /**
     * Non-super-admins may only grant permissions they hold themselves.
     *
     * @param  list<string>  $permissions
     */
    public function ensureCanGrant(User $actor, array $permissions): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                throw new BusinessRuleException('Anda tidak dapat memberikan permission yang tidak Anda miliki ('.$permission.').');
            }
        }
    }
}
