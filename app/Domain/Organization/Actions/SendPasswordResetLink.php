<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Administrator-initiated password reset: the user receives the standard reset
 * email, so the administrator never learns the new password.
 */
class SendPasswordResetLink
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user): void
    {
        if (blank($user->email)) {
            throw new BusinessRuleException('User belum memiliki email. Isi email terlebih dahulu atau atur password baru melalui Edit.');
        }

        if (! $user->is_active) {
            throw new BusinessRuleException('Aktifkan user terlebih dahulu sebelum mengirim link reset password.');
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            throw new BusinessRuleException('Link reset password baru saja dikirim. Coba lagi beberapa saat lagi.');
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new BusinessRuleException('Link reset password gagal dikirim.');
        }

        $this->audit->record('user.password_reset_link_sent', 'Mengirim link reset password ke '.$user->name, $user);
    }
}
