<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a user's profile data, system role and department memberships.
 * Role and memberships are delegated to their own actions (and audit events).
 */
class UpdateUser
{
    public function __construct(
        private AuditLogger $audit,
        private AssignUserRole $assignRole,
        private SyncUserMemberships $syncMemberships,
    ) {}

    /**
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null, password?: string|null, role?: string, memberships?: list<array{department_id: int|string, position_id?: int|string|null, is_leader?: bool, is_primary?: bool}>}  $data
     */
    public function handle(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $passwordChanged = $user->isDirty('password');
            $user->save();

            $this->audit->recordChanges('user.updated', 'Mengubah data user '.$user->name, $user);

            if ($passwordChanged) {
                $this->audit->record('user.password_changed_by_admin', 'Mengganti password user '.$user->name, $user);
            }

            if (isset($data['role'])) {
                $this->assignRole->handle($user, $data['role'], $actor);
            }

            if (array_key_exists('memberships', $data)) {
                $this->syncMemberships->handle($user, $data['memberships']);
            }

            return $user;
        });
    }
}
