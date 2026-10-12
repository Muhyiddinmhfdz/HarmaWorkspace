<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Synchronise permissions and system roles with the code registry.
 *
 * Idempotent: safe to run on every deployment. Existing non-super-admin roles
 * keep the permissions administrators gave them; only new roles receive the
 * defaults. The Super Admin role always holds every permission.
 */
class SyncPermissionRegistry
{
    public function handle(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = PermissionRegistry::all();

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Permission::query()->where('guard_name', 'web')->whereNotIn('name', $names)->delete();

        Role::findOrCreate(config('harma.super_admin_role'), 'web')->syncPermissions($names);

        foreach (PermissionRegistry::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                Role::create(['name' => $roleName, 'guard_name' => 'web'])->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
