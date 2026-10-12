<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Support\TransitionContext;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Enums\MasterScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Move a project to another status allowed by its workflow version
 * (WorkflowGate). Completion has its own action (CompleteProject) because
 * it sets actual dates, progress and health. The first move away from the
 * initial status records the actual start.
 */
class ChangeProjectStatus
{
    public function __construct(
        private AuditLogger $audit,
        private ScopedMasterGuard $masters,
        private ProjectGuard $guard,
        private WorkflowGate $workflow,
    ) {}

    public function handle(Project $project, int $statusId, User $actor, ?TransitionContext $context = null): Project
    {
        $this->guard->ensureEditable($project);

        if ($project->status_id === $statusId) {
            return $project;
        }

        $status = $this->masters->status($statusId, MasterScope::Project);

        if ($status->counts_as_completed) {
            throw new BusinessRuleException('Gunakan aksi "Complete project" untuk menyelesaikan project.');
        }

        return DB::transaction(function () use ($project, $status, $actor, $context): Project {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $previous = $project->status()->firstOrFail();

            $transition = $this->workflow->resolve($project, $previous, $status, $context);

            $attributes = ['status_id' => $status->id, 'updated_by' => $actor->id];

            if (! $status->is_initial && ! $status->is_terminal && $project->actual_start === null) {
                $attributes['actual_start'] = Date::today();
            }

            $project->forceFill($attributes)->save();

            $this->audit->record(
                event: 'project.status_changed',
                description: 'Mengubah status project menjadi '.$status->name,
                subject: $project,
                old: ['status' => $previous->name],
                new: ['status' => $status->name],
            );
            $this->workflow->record($project, $transition, $previous, $status, $actor, null, array_filter(['approval_request_id' => $context?->approvalRequestId]));

            return $project;
        });
    }
}
