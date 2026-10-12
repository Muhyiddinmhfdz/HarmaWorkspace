<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Assign the single system role of a user.
 *
 * Guards against privilege escalation: nobody changes their own role, only a
 * Super Admin grants or revokes Super Admin, other actors may only grant roles
 * whose permissions they hold themselves, and the last active Super Admin
 * cannot be demoted.
 */
class AssignUserRole
{
    public function __construct(
        private AuditLogger $audit,
        private SuperAdminGuard $superAdmins,
    ) {}

    /**
     * @param  User|null  $actor  Null when run by the system (seeders, console).
     */
    public function handle(User $user, string $roleName, ?User $actor): void
    {
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->firstOrFail();
        $currentRoles = $user->getRoleNames()->all();

        if ($currentRoles === [$role->name]) {
            return;
        }

        if ($actor !== null) {
            $this->guardActor($user, $role, $actor);
        }

        if ($user->isSuperAdmin() && ! $this->isSuperAdminRole($role)) {
            $this->superAdmins->ensureAnotherActiveSuperAdmin($user);
        }

        $user->syncRoles([$role]);

        if ($user->wasRecentlyCreated) {
            return;
        }

        $this->audit->record(
            event: 'user.role_changed',
            description: 'Mengubah role user '.$user->name.' menjadi '.$role->name,
            subject: $user,
            old: ['role' => $currentRoles],
            new: ['role' => [$role->name]],
        );
    }

    private function guardActor(User $user, Role $role, User $actor): void
    {
        if ($actor->is($user)) {
            throw new BusinessRuleException('Anda tidak dapat mengubah role akun Anda sendiri.');
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($this->isSuperAdminRole($role) || $user->isSuperAdmin()) {
            throw new BusinessRuleException('Hanya Super Admin yang dapat mengatur role Super Admin.');
        }

        $missing = $role->permissions->pluck('name')
            ->reject(fn (string $permission): bool => $actor->can($permission));

        if ($missing->isNotEmpty()) {
            throw new BusinessRuleException('Anda tidak dapat memberikan role dengan permission yang tidak Anda miliki.');
        }
    }

    private function isSuperAdminRole(Role $role): bool
    {
        return $role->name === config('harma.super_admin_role');
    }
}
