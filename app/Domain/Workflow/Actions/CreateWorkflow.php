<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\WorkflowEntity;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a workflow with its first draft — empty, or copied from another
 * workflow of the same entity (its published version), e.g. "Bug Workflow"
 * starting from the default ticket workflow. Also used to duplicate.
 */
class CreateWorkflow
{
    public function __construct(
        private CloneWorkflowVersion $clone,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, entity_type: string, copy_from_workflow_id?: int|null}  $data
     */
    public function handle(array $data, User $actor): Workflow
    {
        $entity = WorkflowEntity::from($data['entity_type']);
        $source = null;

        if (filled($data['copy_from_workflow_id'] ?? null)) {
            $sourceWorkflow = Workflow::query()->forEntity($entity)->find((int) $data['copy_from_workflow_id'])
                ?? throw new BusinessRuleException('Workflow sumber tidak ditemukan untuk entity ini.');
            $source = $sourceWorkflow->publishedVersion()->first() ?? $sourceWorkflow->versions()->first();
        }

        return DB::transaction(function () use ($data, $actor, $entity, $source): Workflow {
            $workflow = new Workflow(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $workflow->forceFill([
                'entity_type' => $entity,
                'code' => $this->code($entity, $data['name']),
                'is_default' => false,
                'is_active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->clone->handle($source, $workflow, $actor);

            $this->audit->record('workflow.created', 'Membuat workflow '.$workflow->name.' ('.$entity->label().')', $workflow, new: ['name' => $workflow->name, 'entity' => $entity->value, 'copied_from_version' => $source?->id]);

            return $workflow;
        });
    }

    private function code(WorkflowEntity $entity, string $name): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '_')) ?: 'WORKFLOW', 34, '');
        $code = $base;
        $suffix = 2;

        while (Workflow::query()->forEntity($entity)->where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }
}
