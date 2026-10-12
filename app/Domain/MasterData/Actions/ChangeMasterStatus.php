<?php

namespace App\Domain\MasterData\Actions;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Activate or deactivate a master record (department, position, client, ...).
 *
 * Masters are never hard-deleted once used; deactivation hides them from new
 * selections while existing references stay valid.
 */
class ChangeMasterStatus
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Model $record, bool $active): Model
    {
        if ((bool) $record->getAttribute('is_active') === $active) {
            return $record;
        }

        $record->forceFill(['is_active' => $active])->save();

        $type = (string) Relation::getMorphAlias($record::class);
        $name = $record->getAttribute('name');

        $this->audit->recordChanges(
            event: $type.($active ? '.activated' : '.deactivated'),
            description: ($active ? 'Mengaktifkan ' : 'Menonaktifkan ').str_replace('_', ' ', $type).' '.$name,
            subject: $record,
        );

        return $record;
    }
}
