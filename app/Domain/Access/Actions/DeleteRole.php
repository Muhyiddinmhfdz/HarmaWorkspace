<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Delete a role that no user holds. Roles carry no history of their own; the
 * audit log keeps the record of the deletion.
 */
class DeleteRole
{
    public function __construct(
        private AuditLogger $audit,
        private RoleGuard $guard,
    ) {}

    public function handle(Role $role): void
    {
        $this->guard->ensureEditable($role);

        if ($role->users()->exists()) {
            throw new BusinessRuleException('Role masih digunakan oleh user dan tidak dapat dihapus.');
        }

        DB::transaction(function () use ($role): void {
            $this->audit->record(
                event: 'role.deleted',
                description: 'Menghapus role '.$role->name,
                old: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()],
                properties: ['role_id' => $role->id],
            );

            $role->delete();
        });
    }
}
