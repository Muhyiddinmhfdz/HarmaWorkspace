<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;

class ChangeOwnPassword
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();

        $this->audit->record('user.password_changed', 'Mengganti password sendiri', $user);
    }
}
