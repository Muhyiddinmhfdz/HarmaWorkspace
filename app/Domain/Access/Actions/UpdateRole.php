<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Rename a role and replace its permissions. Each change is audited with the
 * permissions that were added and removed.
 */
class UpdateRole
{
    public function __construct(
        private AuditLogger $audit,
        private RoleGuard $guard,
    ) {}

    /**
     * @param  array{name: string, permissions?: list<string>}  $data
     */
    public function handle(Role $role, array $data, User $actor): Role
    {
        $this->guard->ensureEditable($role);

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $after = collect($data['permissions'] ?? [])->unique()->sort()->values()->all();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        // Granting needs the permission; removing one you lack would also be an escalation path.
        $this->guard->ensureCanGrant($actor, [...$added, ...$removed]);

        return DB::transaction(function () use ($role, $data, $before, $after, $added, $removed): Role {
            $oldName = $role->name;
            $role->update(['name' => trim($data['name'])]);
            $role->syncPermissions($after);

            if ($oldName !== $role->name || $added !== [] || $removed !== []) {
                $this->audit->record(
                    event: 'role.updated',
                    description: 'Mengubah role '.$role->name,
                    subject: $role,
                    old: ['name' => $oldName, 'permissions' => $before],
                    new: ['name' => $role->name, 'permissions' => $after],
                    properties: ['permissions_added' => $added, 'permissions_removed' => $removed],
                );
            }

            return $role;
        });
    }
}
