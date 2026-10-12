<?php

namespace App\Domain\MasterData\Rules;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules shared by every master record.
 */
class MasterRules
{
    /**
     * Business code: uppercase letters, digits, dash and underscore.
     *
     * @param  class-string  $model
     * @return list<mixed>
     */
    public static function code(string $model, ?int $ignoreId = null): array
    {
        return [
            'required',
            'string',
            'max:30',
            'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
            self::unique($model, 'code', $ignoreId),
        ];
    }

    /**
     * @return list<string>
     */
    public static function sortOrder(): array
    {
        return ['required', 'integer', 'min:0', 'max:65535'];
    }

    /**
     * @param  class-string  $model
     */
    private static function unique(string $model, string $column, ?int $ignoreId): Unique
    {
        $rule = Rule::unique($model, $column);

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
