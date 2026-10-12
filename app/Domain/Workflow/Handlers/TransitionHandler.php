<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Support\TransitionContext;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * Bridge between the Workflow Engine (WHAT may happen) and the domain
 * actions (the business EFFECTS, ADR-068). One handler per entity type maps
 * each transition operation to the existing domain action.
 */
interface TransitionHandler
{
    /**
     * Domain operations an administrator can attach to a transition.
     *
     * @return array<string, string> operation => friendly label
     */
    public function operations(): array;

    /**
     * Why an operation cannot be used between these statuses (null = valid).
     */
    public function incompatibility(string $operation, Status $from, Status $to): ?string;

    /**
     * Extra availability check on top of the workflow (e.g. resume status).
     */
    public function isAvailable(Model $record, WorkflowTransition $transition): bool;

    /**
     * Domain prerequisites checked before an approval request is created
     * (and again when the approved transition executes).
     *
     * @param  array<string, mixed>  $input
     */
    public function precheck(Model $record, WorkflowTransition $transition, array $input): void;

    /**
     * Run the domain action for the transition.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(Model $record, WorkflowTransition $transition, User $actor, array $input, TransitionContext $context): Model;
}
