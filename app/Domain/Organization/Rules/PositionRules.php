<?php

namespace App\Domain\Organization\Rules;

use App\Domain\MasterData\Rules\MasterRules;
use App\Models\Position;

class PositionRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?Position $position = null): array
    {
        return [
            'code' => MasterRules::code(Position::class, $position?->id),
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'sort_order' => MasterRules::sortOrder(),
        ];
    }
}
