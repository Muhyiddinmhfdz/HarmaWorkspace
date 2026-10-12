<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CreateRole
{
    public function __construct(
        private AuditLogger $audit,
        private RoleGuard $guard,
    ) {}

    /**
     * @param  array{name: string, permissions?: list<string>}  $data
     */
    public function handle(array $data, User $actor): Role
    {
        $permissions = $data['permissions'] ?? [];

        $this->guard->ensureCanGrant($actor, $permissions);

        return DB::transaction(function () use ($data, $permissions): Role {
            $role = Role::query()->create(['name' => trim($data['name']), 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            $this->audit->record(
                event: 'role.created',
                description: 'Membuat role '.$role->name,
                subject: $role,
                new: ['name' => $role->name, 'permissions' => $permissions],
            );

            return $role;
        });
    }
}
