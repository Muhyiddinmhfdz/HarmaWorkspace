<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\WorkflowTransitionHistory;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Only never-used drafts can be deleted (ADR-073). Published and archived
 * versions are history and are never hard-deleted.
 */
class DeleteDraftVersion
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(WorkflowVersion $version): void
    {
        if (! $version->isDraft()) {
            throw new BusinessRuleException('Versi yang pernah dipublikasikan tidak dapat dihapus.');
        }

        $workflow = $version->workflow()->firstOrFail();
        $model = $workflow->entity_type->modelClass();
        $used = $model::query()->where('workflow_version_id', $version->id)->exists()
            || WorkflowTransitionHistory::query()->where('workflow_version_id', $version->id)->exists();

        if ($used) {
            throw new BusinessRuleException('Draft ini sudah dipakai dan tidak dapat dihapus.');
        }

        DB::transaction(function () use ($version, $workflow): void {
            $version->transitions()->delete();
            $version->states()->delete();
            $version->delete();

            $this->audit->record('workflow.version_deleted', 'Menghapus draft '.$version->label().' workflow '.$workflow->name, $workflow);
        });
    }
}
