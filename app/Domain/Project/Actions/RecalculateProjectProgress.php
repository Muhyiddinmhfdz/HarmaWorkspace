<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Support\ProjectProgressCalculator;
use App\Enums\ProgressMode;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The single place that stores task-weighted project progress. Called by every
 * task action that can change it (create, status, progress, weight, estimate).
 * The project row is locked so concurrent task updates serialise and the
 * stored value always matches the latest task data. Manual projects are left
 * untouched.
 */
class RecalculateProjectProgress
{
    public function __construct(private ProjectProgressCalculator $calculator) {}

    public function handle(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        DB::transaction(function () use ($projectId): void {
            $project = Project::query()->lockForUpdate()->find($projectId);

            if ($project === null || $project->progress_mode !== ProgressMode::TaskWeighted) {
                return;
            }

            $progress = $this->calculator->overall($project)['progress'];

            if ($project->progress_percentage !== $progress) {
                $project->forceFill(['progress_percentage' => $progress])->saveQuietly();
            }
        });
    }
}
