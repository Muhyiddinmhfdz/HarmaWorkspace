<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Workflow\Support\TransitionContext;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ProjectTransitionHandler implements TransitionHandler
{
    public function __construct(
        private ChangeProjectStatus $changeStatus,
        private CompleteProject $complete,
    ) {}

    public function operations(): array
    {
        return [
            'move' => 'Change status (records actual start on first move)',
            'complete' => 'Complete project (validates open tasks/tickets, sets actual end & 100%)',
        ];
    }

    public function incompatibility(string $operation, Status $from, Status $to): ?string
    {
        return match ($operation) {
            'complete' => $to->counts_as_completed ? null : 'Complete project harus menuju status "completed".',
            'move' => $to->counts_as_completed ? 'Status "completed" hanya dapat dicapai lewat operasi Complete project.' : null,
            default => 'Operasi tidak dikenal untuk Project.',
        };
    }

    public function isAvailable(Model $record, WorkflowTransition $transition): bool
    {
        return true;
    }

    public function precheck(Model $record, WorkflowTransition $transition, array $input): void
    {
        if ($transition->operation === 'complete') {
            $this->complete->ensureNoActiveWork($this->project($record));
        }
    }

    public function execute(Model $record, WorkflowTransition $transition, User $actor, array $input, TransitionContext $context): Model
    {
        $project = $this->project($record);

        return $transition->operation === 'complete'
            ? $this->complete->handle($project, $actor, isset($input['actual_end']) && is_string($input['actual_end']) && $input['actual_end'] !== '' ? $input['actual_end'] : null, $context)
            : $this->changeStatus->handle($project, $transition->toState->status_id, $actor, $context);
    }

    private function project(Model $record): Project
    {
        return $record instanceof Project ? $record : throw new InvalidArgumentException('Project expected.');
    }
}
