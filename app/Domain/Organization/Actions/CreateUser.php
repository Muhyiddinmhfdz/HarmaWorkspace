<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create an internal account. There is no public registration; accounts are
 * created by users holding `user.create` or by the system seeder.
 */
class CreateUser
{
    public function __construct(
        private AuditLogger $audit,
        private AssignUserRole $assignRole,
        private SyncUserMemberships $syncMemberships,
    ) {}

    /**
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null, password: string, role: string, is_active?: bool, memberships?: list<array{department_id: int|string, position_id?: int|string|null, is_leader?: bool, is_primary?: bool}>}  $data
     */
    public function handle(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $user->forceFill(['is_active' => $data['is_active'] ?? true])->save();

            $this->assignRole->handle($user, $data['role'], $actor);

            $this->audit->recordCreated('user.created', 'Membuat user '.$user->name, $user, [
                'role' => $data['role'],
            ]);

            $this->syncMemberships->handle($user, $data['memberships'] ?? []);

            return $user;
        });
    }
}
