<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Events\WorkflowTransitionExecuted;
use App\Enums\WorkflowEntity;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Used by every domain action that changes a governed status (ADR-066):
 * validates the change against the record's workflow version on the locked
 * row (stale, cross-workflow and approval protection) and records the
 * immutable transition history. Domain actions keep the side effects.
 */
class WorkflowGate
{
    public const string STALE_MESSAGE = 'Status sudah berubah. Muat ulang data terbaru.';

    public function __construct(
        private WorkflowRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /**
     * The transition allowing $from → $to (or the given operation) for the
     * record. Binds records created before workflows existed.
     */
    public function resolve(Model $record, Status $from, ?Status $to, ?TransitionContext $context = null, ?string $operation = null): WorkflowTransition
    {
        $version = $this->registry->versionOf($record);

        if ($record->getAttribute('workflow_version_id') === null) {
            $record->setAttribute('workflow_version_id', $version->id);
        }

        if ($context?->transition !== null) {
            $transition = $version->transitions->firstWhere('id', $context->transition->id);

            if ($transition === null) {
                throw new BusinessRuleException('Aksi ini tidak berlaku untuk proses record ini.');
            }

            if (! $transition->is_active || $transition->fromState->status_id !== $from->id
                || ($to !== null && $transition->toState->status_id !== $to->id)
                || ($operation !== null && $transition->operation !== $operation)) {
                throw new BusinessRuleException(self::STALE_MESSAGE);
            }
        } else {
            $transition = collect($this->registry->outgoing($version, $from->id))
                ->first(fn (WorkflowTransition $candidate): bool => ($to === null || $candidate->toState->status_id === $to->id)
                    && ($operation === null || $candidate->operation === $operation));

            if ($transition === null) {
                throw new BusinessRuleException($this->rejectionMessage($record, $from, $to, $operation, array_values($version->transitions->all())));
            }
        }

        if ($transition->requiresApproval() && ($context === null || ! $context->approved)) {
            throw new BusinessRuleException('Aksi "'.$transition->action_label.'" membutuhkan approval. Ajukan dari halaman record.');
        }

        return $transition;
    }

    /**
     * Non-throwing lookup (e.g. "move to Assigned if the workflow allows it").
     */
    public function find(Model $record, Status $from, string $operation): ?WorkflowTransition
    {
        $version = $this->registry->versionOf($record);

        return collect($this->registry->outgoing($version, $from->id))
            ->first(fn (WorkflowTransition $candidate): bool => $candidate->operation === $operation && ! $candidate->requiresApproval());
    }

    /**
     * Initial status of the record's workflow (new records, transfers).
     */
    public function initialStatus(Model $record): Status
    {
        return $this->registry->initialState($this->registry->versionOf($record))->status;
    }

    /**
     * Immutable history row + technical audit + domain event (after commit).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(Model $record, ?WorkflowTransition $transition, ?Status $from, Status $to, ?User $actor, ?string $comment = null, array $metadata = []): void
    {
        $version = $this->registry->versionOf($record);
        $toState = $this->registry->stateFor($version, $to->id)
            ?? throw new BusinessRuleException('Status '.$to->name.' tidak ada pada workflow record ini.');
        $fromState = $from !== null ? $this->registry->stateFor($version, $from->id) : null;

        $history = new WorkflowTransitionHistory;
        $history->forceFill([
            'workflow_version_id' => $version->id,
            'workflow_transition_id' => $transition?->id,
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
            'from_state_id' => $fromState?->id,
            'to_state_id' => $toState->id,
            'actor_id' => $actor?->id,
            'comment' => filled($comment) ? $comment : null,
            'metadata' => $metadata !== [] ? $metadata : null,
            'executed_at' => Date::now(),
        ])->save();

        $this->audit->record(
            event: 'workflow.transition_executed',
            description: ($transition !== null ? $transition->action_label : 'Perubahan status').': '.($from !== null ? $from->name : '—').' → '.$to->name,
            subject: $record,
            old: ['status' => $from?->name],
            new: ['status' => $to->name],
            properties: array_filter([
                'workflow' => $version->workflow->name,
                'workflow_version' => $version->version_number,
                'transition' => $transition?->code,
                'history_id' => $history->id,
                ...$metadata,
            ], fn ($value) => $value !== null),
        );

        DB::afterCommit(fn () => WorkflowTransitionExecuted::dispatch($record, $history));
    }

    /**
     * @param  list<WorkflowTransition>  $all
     */
    private function rejectionMessage(Model $record, Status $from, ?Status $to, ?string $operation, array $all): string
    {
        $target = $to?->name;

        if ($target === null && $operation !== null) {
            $target = collect($all)->first(fn (WorkflowTransition $transition): bool => $transition->operation === $operation)?->toState->status->name;
        }

        return 'Status '.WorkflowEntity::fromModel($record)->label().' tidak dapat diubah dari '.$from->name.($target !== null ? ' ke '.$target : '').'.';
    }
}
