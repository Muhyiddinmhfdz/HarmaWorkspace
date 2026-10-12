<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Activate or deactivate a user. Users are never deleted because they own
 * history. Deactivation ends every session and invalidates "remember me".
 */
class ChangeUserStatus
{
    public function __construct(
        private AuditLogger $audit,
        private SuperAdminGuard $superAdmins,
    ) {}

    public function handle(User $user, bool $active, User $actor): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        if (! $active) {
            $this->guardDeactivation($user, $actor);
        }

        DB::transaction(function () use ($user, $active): void {
            $user->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : Date::now(),
            ]);

            if (! $active) {
                $user->setRememberToken(Str::random(60));
            }

            $user->save();

            if (! $active) {
                $this->revokeSessions($user);
            }

            $this->audit->record(
                event: $active ? 'user.activated' : 'user.deactivated',
                description: ($active ? 'Mengaktifkan user ' : 'Menonaktifkan user ').$user->name,
                subject: $user,
                old: ['is_active' => ! $active],
                new: ['is_active' => $active],
            );
        });

        return $user;
    }

    private function guardDeactivation(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw new BusinessRuleException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if ($user->isSuperAdmin()) {
            if (! $actor->isSuperAdmin()) {
                throw new BusinessRuleException('Hanya Super Admin yang dapat menonaktifkan Super Admin.');
            }

            $this->superAdmins->ensureAnotherActiveSuperAdmin($user);
        }
    }

    private function revokeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
