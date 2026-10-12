<?php

namespace App\Domain\MasterData\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\MasterDefinition;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or update a record of a generic master (see MasterRegistry).
 */
class SaveMasterRecord
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated values (code, name, description, sort_order, definition fields).
     */
    public function handle(MasterDefinition $definition, ?Model $record, array $data): Model
    {
        $attributes = [
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => filled($data['description'] ?? null) ? $data['description'] : null,
            'sort_order' => (int) $data['sort_order'],
        ];

        foreach ($definition->fields as $field) {
            $value = $data[$field['name']] ?? null;
            $attributes[$field['name']] = match ($field['type']) {
                'boolean' => (bool) $value,
                'number' => (int) $value,
                default => $value,
            };
        }

        return DB::transaction(function () use ($definition, $record, $attributes): Model {
            if ($record !== null && $record->getAttribute('code') !== Str::upper(trim((string) $attributes['code']))
                && method_exists($record, 'isCodeLocked') && $record->isCodeLocked()) {
                throw new BusinessRuleException('Code tidak dapat diubah karena data ini sudah digunakan.');
            }

            $creating = $record === null;
            $record ??= new ($definition->model);

            if ($definition->scope !== null) {
                $record->setAttribute('scope', $definition->scope);
            }

            $record->fill($attributes)->save();

            $this->releaseExclusiveFlags($definition, $record);

            $alias = Relation::getMorphAlias($record::class);

            $creating
                ? $this->audit->recordCreated($alias.'.created', 'Membuat '.$definition->singular.' '.$record->getAttribute('name'), $record)
                : $this->audit->recordChanges($alias.'.updated', 'Mengubah '.$definition->singular.' '.$record->getAttribute('name'), $record);

            return $record;
        });
    }

    /**
     * Only one record (per scope) may hold an exclusive flag such as "initial".
     */
    private function releaseExclusiveFlags(MasterDefinition $definition, Model $record): void
    {
        foreach ($definition->exclusiveFlags as $flag) {
            if (! $record->getAttribute($flag)) {
                continue;
            }

            $definition->model::query()
                ->whereKeyNot($record->getKey())
                ->when($definition->scope !== null, fn ($query) => $query->where('scope', $definition->scope?->value))
                ->where($flag, true)
                ->update([$flag => false]);
        }
    }
}
