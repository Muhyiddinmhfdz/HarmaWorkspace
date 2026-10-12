<?php

namespace App\Domain\Organization\Rules;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserRules
{
    /**
     * Rules for creating or updating a user from the administration form.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => self::usernameRules($user),
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                $user === null ? Rule::unique(User::class) : Rule::unique(User::class)->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => $user === null
                ? ['required', 'string', Password::default(), 'confirmed']
                : ['nullable', 'string', Password::default(), 'confirmed'],
            'role' => ['required', 'string', Rule::exists(Role::class, 'name')],
            'is_active' => ['boolean'],
            ...self::membershipRules(),
        ];
    }

    /**
     * Login username: lowercase letters, digits, dot, dash and underscore.
     *
     * @return list<mixed>
     */
    public static function usernameRules(?User $user = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:50',
            'regex:/^[a-z0-9][a-z0-9._-]*$/',
            $user === null ? Rule::unique(User::class) : Rule::unique(User::class)->ignore($user->id),
        ];
    }

    /**
     * Rules for the department membership list. The domain action enforces the
     * remaining invariants (one primary, active masters for new memberships).
     *
     * @return array<string, list<mixed>>
     */
    public static function membershipRules(): array
    {
        return [
            'memberships' => ['array'],
            'memberships.*.department_id' => ['required', 'integer', 'distinct', Rule::exists(Department::class, 'id')],
            'memberships.*.position_id' => ['nullable', 'integer', Rule::exists(Position::class, 'id')],
            'memberships.*.is_leader' => ['boolean'],
            'memberships.*.is_primary' => ['boolean'],
        ];
    }
}
