<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switch between manual and task-weighted progress (ADR-046).
 * manual → task_weighted: progress is recalculated from tasks immediately.
 * task_weighted → manual: the last calculated value becomes the manual value.
 */
class ChangeProjectProgressMode
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
        private RecalculateProjectProgress $recalculate,
    ) {}

    public function handle(Project $project, ProgressMode $mode, User $actor): Project
    {
        if ($project->progress_mode === $mode) {
            return $project;
        }

        $this->guard->ensureEditable($project);

        return DB::transaction(function () use ($project, $mode, $actor): Project {
            $previousMode = $project->progress_mode;
            $previousProgress = $project->progress_percentage;

            $project->forceFill(['progress_mode' => $mode, 'updated_by' => $actor->id])->save();
            $this->recalculate->handle($project->id);
            $project->refresh();

            $this->audit->record(
                event: 'project.progress_mode_changed',
                description: $mode === ProgressMode::TaskWeighted
                    ? 'Mengaktifkan progress otomatis berdasarkan Task'
                    : 'Mengubah progress project menjadi manual',
                subject: $project,
                old: ['progress_mode' => $previousMode->value, 'progress_percentage' => $previousProgress],
                new: ['progress_mode' => $mode->value, 'progress_percentage' => $project->progress_percentage],
            );

            return $project;
        });
    }
}
