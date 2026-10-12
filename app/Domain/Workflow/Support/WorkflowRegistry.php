<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\WorkflowEntity;
use App\Enums\WorkflowVersionStatus;
use App\Models\Workflow;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which workflow version governs a record and serves version
 * definitions (states with statuses, transitions with rules), cached per
 * request because published versions never change.
 */
class WorkflowRegistry
{
    /**
     * @var array<int, WorkflowVersion>
     */
    private array $definitions = [];

    /**
     * Current published version of the entity's default workflow.
     */
    public function defaultVersion(WorkflowEntity $entity): WorkflowVersion
    {
        $workflow = Workflow::query()->forEntity($entity)->usable()->where('is_default', true)->first()
            ?? throw new BusinessRuleException('Belum ada workflow default untuk '.$entity->label().'. Hubungi administrator.');

        return $this->definition((int) $workflow->publishedVersion()->value('id'));
    }

    /**
     * Version for a new record: the chosen usable workflow, or the default.
     */
    public function versionForNew(WorkflowEntity $entity, ?int $workflowId = null): WorkflowVersion
    {
        if ($workflowId === null) {
            return $this->defaultVersion($entity);
        }

        $workflow = Workflow::query()->forEntity($entity)->usable()->find($workflowId)
            ?? throw new BusinessRuleException('Proses yang dipilih tidak tersedia.');

        return $this->definition((int) $workflow->publishedVersion()->value('id'));
    }

    /**
     * Version the record runs on (records created before binding fall back
     * to the default; callers persist the binding).
     */
    public function versionOf(Model $record): WorkflowVersion
    {
        $versionId = $record->getAttribute('workflow_version_id');

        return $versionId !== null
            ? $this->definition((int) $versionId)
            : $this->defaultVersion(WorkflowEntity::fromModel($record));
    }

    public function definition(int $versionId): WorkflowVersion
    {
        if (isset($this->definitions[$versionId])) {
            return $this->definitions[$versionId];
        }

        $version = WorkflowVersion::query()
            ->with([
                'workflow',
                'states.status',
                'transitions' => fn ($query) => $query->with(['rules', 'fromState.status', 'toState.status', 'approvalTemplate']),
            ])
            ->findOrFail($versionId);

        if ($version->status === WorkflowVersionStatus::Draft) {
            return $version; // Drafts are mutable: never cached.
        }

        return $this->definitions[$versionId] = $version;
    }

    public function forget(?int $versionId = null): void
    {
        if ($versionId === null) {
            $this->definitions = [];
        } else {
            unset($this->definitions[$versionId]);
        }
    }

    public function stateFor(WorkflowVersion $version, int $statusId): ?WorkflowState
    {
        return $version->states->first(fn (WorkflowState $state): bool => $state->status_id === $statusId);
    }

    public function initialState(WorkflowVersion $version): WorkflowState
    {
        return $version->states->first(fn (WorkflowState $state): bool => $state->is_initial)
            ?? throw new BusinessRuleException('Workflow '.$version->workflow->name.' tidak memiliki state awal.');
    }

    /**
     * Active transitions leaving the record's current status.
     *
     * @return list<WorkflowTransition>
     */
    public function outgoing(WorkflowVersion $version, int $statusId): array
    {
        return array_values($version->transitions
            ->filter(fn (WorkflowTransition $transition): bool => $transition->is_active && $transition->fromState->status_id === $statusId)
            ->all());
    }
}
