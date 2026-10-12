<?php

namespace App\Console\Commands;

use App\Domain\Organization\Actions\CreateUser;
use App\Domain\Organization\Rules\UserRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('harma:create-super-admin {--name= : Full name} {--username= : Login username} {--email= : Optional email for password resets}')]
#[Description('Create a Super Admin account (the password is asked interactively and never stored in files)')]
class CreateSuperAdmin extends Command
{
    public function handle(CreateUser $createUser): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $username = strtolower(trim((string) ($this->option('username') ?: text('Username', required: true))));
        $email = $this->option('email') ?: text('Email (optional)');
        $secret = password('Password', required: true, hint: 'Minimal mengikuti kebijakan password aplikasi.');

        $validator = Validator::make(
            ['name' => $name, 'username' => $username, 'email' => $email ?: null, 'password' => $secret],
            [
                'name' => ['required', 'string', 'max:255'],
                'username' => UserRules::usernameRules(),
                'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = $createUser->handle([
            'name' => $name,
            'username' => $username,
            'email' => $email ? strtolower($email) : null,
            'password' => $secret,
            'role' => config('harma.super_admin_role'),
        ], actor: null);

        $this->components->info("Super Admin {$user->username} berhasil dibuat.");

        return self::SUCCESS;
    }
}
