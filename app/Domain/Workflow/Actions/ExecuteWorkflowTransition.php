<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Approval\Actions\RequestApproval;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Handlers\TransitionHandlers;
use App\Domain\Workflow\Support\TransitionContext;
use App\Domain\Workflow\Support\TransitionOutcome;
use App\Domain\Workflow\Support\TransitionRules;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Enums\ApprovalRequestStatus;
use App\Enums\WorkflowEntity;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single entry point for user-initiated transitions (ADR-066):
 *
 * 1. the actor must be able to see the record;
 * 2. the transition must belong to the record's own workflow version
 *    (no cross-workflow / cross-entity ids) and leave its CURRENT status;
 * 3. the actor must satisfy the "who" rules;
 * 4. requirement rules (comment, reason, attachment, PIC, resolution);
 * 5. approval transitions create an approval request after the domain
 *    prechecks; others run the domain handler, which locks the row,
 *    re-validates (stale protection), applies side effects and records the
 *    history — all in one transaction.
 */
class ExecuteWorkflowTransition
{
    public function __construct(
        private WorkflowRegistry $registry,
        private TransitionRules $rules,
        private TransitionHandlers $handlers,
        private RequestApproval $approvals,
    ) {}

    /**
     * Transitions the user may run now (UI "available actions").
     *
     * @return list<WorkflowTransition>
     */
    public function available(Model $record, User $user): array
    {
        if (! $user->can('view', $record)) {
            return [];
        }

        $handler = $this->handlers->for(WorkflowEntity::fromModel($record));
        $version = $this->registry->versionOf($record);
        $pending = ApprovalRequest::query()
            ->where('approvable_type', $record->getMorphClass())
            ->where('approvable_id', $record->getKey())
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->pluck('workflow_transition_id')
            ->all();

        // A transition already waiting for approval is not offered again.
        return array_values(array_filter(
            $this->registry->outgoing($version, (int) $record->getAttribute('status_id')),
            fn (WorkflowTransition $transition): bool => ! in_array($transition->id, $pending, true)
                && $handler->isAvailable($record, $transition)
                && $this->rules->canPerform($user, $record, $transition),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Model $record, int $transitionId, User $actor, array $input = []): TransitionOutcome
    {
        $transition = $this->authorize($record, $transitionId, $actor);
        $handler = $this->handlers->for(WorkflowEntity::fromModel($record));
        $this->rules->validateInput($record, $transition, $input);

        return DB::transaction(function () use ($record, $transition, $actor, $input, $handler): TransitionOutcome {
            if ($transition->requiresApproval()) {
                $handler->precheck($record, $transition, $input);

                return new TransitionOutcome($record, $this->approvals->forTransition($record, $transition, $actor, $input));
            }

            return new TransitionOutcome($handler->execute($record, $transition, $actor, $input, new TransitionContext($transition)));
        });
    }

    /**
     * Second stage after the last approval: re-checks the domain rules and
     * executes on behalf of the requester. Throws when the record changed.
     */
    public function executeApproved(ApprovalRequest $request): Model
    {
        $record = $request->approvable()->firstOrFail();
        $transition = $request->transition()->firstOrFail();
        $actor = $request->requester()->firstOrFail();
        $version = $this->registry->versionOf($record);
        $transition = $version->transitions->firstWhere('id', $transition->id)
            ?? throw new BusinessRuleException('Aksi ini tidak berlaku lagi untuk proses record ini.');

        if ($transition->fromState->status_id !== (int) $record->getAttribute('status_id')) {
            throw new BusinessRuleException('Status record sudah berubah sejak approval diminta, sehingga aksi "'.$transition->action_label.'" tidak dijalankan.');
        }

        $handler = $this->handlers->for(WorkflowEntity::fromModel($record));
        $input = $request->input ?? [];
        $handler->precheck($record, $transition, $input);

        return $handler->execute($record, $transition, $actor, $input, new TransitionContext($transition, approved: true, approvalRequestId: $request->id));
    }

    private function authorize(Model $record, int $transitionId, User $actor): WorkflowTransition
    {
        Gate::forUser($actor)->authorize('view', $record);

        $version = $this->registry->versionOf($record);
        $transition = $version->transitions->firstWhere('id', $transitionId);

        if ($transition === null || ! $transition->is_active) {
            throw new BusinessRuleException('Aksi ini tidak berlaku untuk proses record ini.');
        }

        if ($transition->fromState->status_id !== (int) $record->getAttribute('status_id')
            || ! $this->handlers->for(WorkflowEntity::fromModel($record))->isAvailable($record, $transition)) {
            throw new BusinessRuleException(WorkflowGate::STALE_MESSAGE);
        }

        if (! $this->rules->canPerform($actor, $record, $transition)) {
            throw new AuthorizationException('Anda tidak berhak menjalankan aksi ini.');
        }

        return $transition;
    }
}
