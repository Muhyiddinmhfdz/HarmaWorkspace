<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual progress update (Phase 2). Projects in task-weighted mode (Phase 3)
 * derive progress from tasks and reject manual updates.
 */
class UpdateProjectProgress
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function handle(Project $project, int $percentage, User $actor): Project
    {
        $this->guard->ensureEditable($project);

        if ($percentage < 0 || $percentage > 100) {
            throw new BusinessRuleException('Progress harus di antara 0 dan 100.');
        }

        return DB::transaction(function () use ($project, $percentage, $actor): Project {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->progress_mode !== ProgressMode::Manual) {
                throw new BusinessRuleException('Progress project ini dihitung otomatis dari task.');
            }

            $previous = $project->progress_percentage;

            if ($previous === $percentage) {
                return $project;
            }

            $project->forceFill(['progress_percentage' => $percentage, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.progress_changed',
                description: 'Mengubah progress project '.$previous.'% → '.$percentage.'%',
                subject: $project,
                old: ['progress_percentage' => $previous],
                new: ['progress_percentage' => $percentage],
            );

            return $project;
        });
    }
}
