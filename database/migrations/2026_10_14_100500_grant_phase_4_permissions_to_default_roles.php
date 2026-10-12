<?php

use App\Domain\Access\Actions\SyncPermissionRegistry;
use App\Domain\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Grants the Phase 4 ticket permissions to the existing default roles once,
     * without touching permissions administrators have customised.
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return; // Fresh install: the SystemSeeder applies the defaults.
        }

        app(SyncPermissionRegistry::class)->handle();

        foreach (PermissionRegistry::PHASE_4_DEFAULTS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
