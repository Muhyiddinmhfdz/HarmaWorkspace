<?php

namespace App\Domain\Access\Rules;

use App\Domain\Access\PermissionRegistry;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?Role $role = null): array
    {
        $unique = Rule::unique(Role::class, 'name')->where('guard_name', 'web');

        return [
            'name' => ['required', 'string', 'max:60', $role === null ? $unique : $unique->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::all())],
        ];
    }
}
