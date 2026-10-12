<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Project\Support\ProjectLifecycle;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\MasterScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Move a project to another status along the allowed lifecycle
 * (ProjectLifecycle). Completion has its own action (CompleteProject) because
 * it sets actual dates, progress and health. The first move away from the
 * initial status records the actual start.
 */
class ChangeProjectStatus
{
    public function __construct(
        private AuditLogger $audit,
        private ScopedMasterGuard $masters,
        private ProjectGuard $guard,
        private ProjectLifecycle $lifecycle,
    ) {}

    public function handle(Project $project, int $statusId, User $actor): Project
    {
        $this->guard->ensureEditable($project);

        if ($project->status_id === $statusId) {
            return $project;
        }

        $status = $this->masters->status($statusId, MasterScope::Project);

        if ($status->counts_as_completed) {
            throw new BusinessRuleException('Gunakan aksi "Complete project" untuk menyelesaikan project.');
        }

        return DB::transaction(function () use ($project, $status, $actor): Project {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $previous = $project->status()->firstOrFail();

            if (! $this->lifecycle->allows($previous, $status)) {
                throw new BusinessRuleException($this->lifecycle->rejectionMessage($previous, $status));
            }

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

            return $project;
        });
    }
}
