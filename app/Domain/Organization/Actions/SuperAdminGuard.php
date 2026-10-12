<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;

/**
 * Keeps at least one active Super Admin so the system can never be locked out.
 */
class SuperAdminGuard
{
    public function ensureAnotherActiveSuperAdmin(User $user): void
    {
        $others = User::role(config('harma.super_admin_role'))
            ->active()
            ->whereKeyNot($user->id)
            ->exists();

        if (! $others) {
            throw new BusinessRuleException('Minimal harus ada satu Super Admin aktif.');
        }
    }
}
