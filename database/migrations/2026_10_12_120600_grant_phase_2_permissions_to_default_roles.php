<?php

use App\Domain\Access\Actions\SyncPermissionRegistry;
use App\Domain\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Data migration: roles that already exist keep their customised
     * permissions, so the new Project permissions are granted to the default
     * roles exactly once here.
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return; // Fresh install: the SystemSeeder applies the defaults.
        }

        app(SyncPermissionRegistry::class)->handle();

        foreach (PermissionRegistry::PHASE_2_DEFAULTS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
