<?php

namespace Database\Seeders\System;

use App\Domain\Organization\Actions\CreateUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first Super Admin from config('harma.super_admin'), which reads
 * HARMA_SUPER_ADMIN_* and falls back to the documented first-time defaults.
 * Skipped when the username already exists.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(CreateUser $createUser): void
    {
        $config = config('harma.super_admin');

        if (blank($config['username']) || blank($config['password'])) {
            $this->command->warn('HARMA_SUPER_ADMIN_USERNAME/PASSWORD kosong. Super Admin tidak dibuat; jalankan `php artisan harma:create-super-admin`.');

            return;
        }

        if (User::query()->where('username', $config['username'])->exists()) {
            return;
        }

        $createUser->handle([
            'name' => $config['name'] ?: 'Super Admin',
            'username' => $config['username'],
            'email' => $config['email'] ?: null,
            'password' => $config['password'],
            'role' => config('harma.super_admin_role'),
        ], actor: null);

        $this->command->info('Super Admin dibuat dengan username: '.$config['username']);

        if (app()->isProduction() && $config['password'] === '123123123') {
            $this->command->warn('Password default sedang dipakai di production. Segera ganti password Super Admin.');
        }
    }
}
