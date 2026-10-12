<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;

/**
 * Self-service profile update. Users may change their name and phone only;
 * email, role, departments and leadership are managed by administrators.
 */
class UpdateOwnProfile
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone?: string|null}  $data
     */
    public function handle(User $user, array $data): User
    {
        $user->fill([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
        ])->save();

        $this->audit->recordChanges('user.profile_updated', 'Memperbarui profil sendiri', $user);

        return $user;
    }
}
