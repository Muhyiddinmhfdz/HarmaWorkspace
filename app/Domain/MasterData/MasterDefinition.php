<?php

namespace App\Domain\MasterData;

use App\Enums\MasterScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Describes a configurable master managed through the generic Master Data
 * page: its model, optional scope, editable fields and validation.
 */
final readonly class MasterDefinition
{
    /**
     * Flux badge colours offered for colour fields.
     *
     * @var list<string>
     */
    public const array COLORS = ['zinc', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];

    /**
     * @param  class-string<Model>  $model
     * @param  list<array{name: string, label: string, type: 'text'|'textarea'|'number'|'color'|'boolean', help?: string}>  $fields
     * @param  list<string>  $exclusiveFlags  Boolean fields only one record (per scope) may have.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $singular,
        public string $description,
        public string $icon,
        public string $model,
        public ?MasterScope $scope = null,
        public array $fields = [],
        public array $exclusiveFlags = [],
    ) {}

    public function table(): string
    {
        return (new ($this->model))->getTable();
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_column($this->fields, 'name');
    }

    public function hasField(string $name): bool
    {
        return in_array($name, $this->fieldNames(), true);
    }

    /**
     * Default form values.
     *
     * @return array<string, string|bool>
     */
    public function blank(): array
    {
        $values = ['code' => '', 'name' => '', 'description' => '', 'sort_order' => '0'];

        foreach ($this->fields as $field) {
            $values[$field['name']] = match ($field['type']) {
                'boolean' => false,
                'color' => 'zinc',
                'number' => '0',
                default => '',
            };
        }

        return $values;
    }

    /**
     * Validation rules keyed for a Livewire `form.*` array.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(?Model $record = null): array
    {
        $unique = Rule::unique($this->table(), 'code');

        if ($this->scope !== null) {
            $unique = $unique->where('scope', $this->scope->value);
        }

        if ($record !== null) {
            $unique = $unique->ignore($record->getKey());
        }

        $rules = [
            'form.code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $unique],
            'form.name' => ['required', 'string', 'max:100'],
            'form.description' => ['nullable', 'string', 'max:1000'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];

        foreach ($this->fields as $field) {
            $rules['form.'.$field['name']] = match ($field['type']) {
                'boolean' => ['boolean'],
                'number' => ['required', 'integer', 'min:0', 'max:1000'],
                'color' => ['required', Rule::in(self::COLORS)],
                'textarea' => ['nullable', 'string', 'max:1000'],
                'text' => ['nullable', 'string', 'max:100'],
            };
        }

        return $rules;
    }
}
