<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\Task\Actions\ChangeTaskStatus;
use App\Domain\Workflow\Support\TransitionContext;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Task side effects are driven by status flags (started, blocked, completed,
 * cancelled) inside ChangeTaskStatus, so every task transition is a "move".
 */
class TaskTransitionHandler implements TransitionHandler
{
    public function __construct(private ChangeTaskStatus $changeStatus) {}

    public function operations(): array
    {
        return ['move' => 'Change status (effects follow the status flags: start, blocked, done, cancel)'];
    }

    public function incompatibility(string $operation, Status $from, Status $to): ?string
    {
        return $operation === 'move' ? null : 'Operasi tidak dikenal untuk Task.';
    }

    public function isAvailable(Model $record, WorkflowTransition $transition): bool
    {
        return true;
    }

    public function precheck(Model $record, WorkflowTransition $transition, array $input): void {}

    public function execute(Model $record, WorkflowTransition $transition, User $actor, array $input, TransitionContext $context): Model
    {
        $task = $record instanceof Task ? $record : throw new InvalidArgumentException('Task expected.');
        $reason = $input['reason'] ?? $input['comment'] ?? null;

        return $this->changeStatus->handle($task, $transition->toState->status_id, $actor, is_string($reason) ? $reason : null, $context);
    }
}
