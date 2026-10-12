<?php

namespace App\Domain\Organization\Rules;

use App\Domain\MasterData\Rules\MasterRules;
use App\Models\Department;
use Illuminate\Validation\Rule;

class DepartmentRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?Department $department = null): array
    {
        return [
            'code' => MasterRules::code(Department::class, $department?->id),
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'sort_order' => MasterRules::sortOrder(),
        ];
    }
}
