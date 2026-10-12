<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Defaults\DefaultWorkflowBlueprint;
use App\Domain\Workflow\Support\WorkflowDefinitionWriter;
use App\Enums\WorkflowEntity;
use App\Enums\WorkflowVersionStatus;
use App\Models\Status;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Production bootstrap (migration + SystemSeeder), idempotent:
 *
 * 1. for each entity without any default workflow, create the default one
 *    from DefaultWorkflowBlueprint and publish v1 — never when the default
 *    code already exists, so administrator workflows are never replaced;
 * 2. bind every record without a workflow version to the current published
 *    version of its default workflow (statuses are not touched).
 */
class EnsureDefaultWorkflows
{
    public function __construct(
        private DefaultWorkflowBlueprint $blueprint,
        private WorkflowDefinitionWriter $writer,
    ) {}

    public function handle(): void
    {
        foreach (WorkflowEntity::cases() as $entity) {
            DB::transaction(fn () => $this->ensure($entity));
            $this->bind($entity);
        }
    }

    private function ensure(WorkflowEntity $entity): void
    {
        $code = DefaultWorkflowBlueprint::CODES[$entity->value];

        if (Workflow::query()->forEntity($entity)->where(fn ($query) => $query->where('is_default', true)->orWhere('code', $code))->exists()) {
            return;
        }

        $statuses = Status::query()->forScope($entity->statusScope())->orderBy('sort_order')->orderBy('id')->get();
        $initial = $statuses->first(fn (Status $status): bool => $status->is_initial && $status->is_active);

        if ($initial === null) {
            return; // Fresh install before masters: the SystemSeeder runs this again.
        }

        $workflow = new Workflow;
        $workflow->forceFill([
            'entity_type' => $entity,
            'code' => $code,
            'name' => 'Default '.$entity->label().' Workflow',
            'description' => 'Proses standar '.strtolower($entity->label()).'. Dibuat otomatis dari lifecycle bawaan HarmaWorkspace.',
            'is_default' => true,
            'is_active' => true,
        ])->save();

        $version = new WorkflowVersion;
        $version->forceFill([
            'workflow_id' => $workflow->id,
            'version_number' => 1,
            'status' => WorkflowVersionStatus::Published,
            'published_at' => Date::now(),
        ])->save();

        $states = [];

        foreach ($statuses as $status) {
            $states[$status->id] = $this->writer->state($version, $status, $status->id === $initial->id);
        }

        foreach ($this->blueprint->transitions($entity, $statuses) as $index => $transition) {
            $this->writer->transition(
                $version,
                $states[$transition['from']->id],
                $states[$transition['to']->id],
                $transition['label'],
                $transition['rules'],
                ['operation' => $transition['operation'], 'sort_order' => ($index + 1) * 10],
            );
        }
    }

    private function bind(WorkflowEntity $entity): void
    {
        $versionId = WorkflowVersion::query()
            ->where('status', WorkflowVersionStatus::Published->value)
            ->whereHas('workflow', fn ($query) => $query->forEntity($entity)->where('is_default', true)->where('is_active', true))
            ->value('id');

        if ($versionId === null) {
            return;
        }

        $model = $entity->modelClass();

        DB::table((new $model)->getTable())->whereNull('workflow_version_id')->update(['workflow_version_id' => $versionId]);
    }
}
