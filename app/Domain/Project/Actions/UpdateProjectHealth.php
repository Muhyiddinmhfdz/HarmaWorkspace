<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual health assessment by the project manager (Phase 2). Later phases may
 * compute health from schedule variance, milestones and tasks.
 */
class UpdateProjectHealth
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function handle(Project $project, int $healthId, ?string $note, User $actor): Project
    {
        $this->guard->ensureEditable($project);

        $health = ProjectHealthStatus::query()->active()->find($healthId)
            ?? throw new BusinessRuleException('Project health yang dipilih tidak tersedia.');

        if ($project->health_status_id === $health->id) {
            return $project;
        }

        return DB::transaction(function () use ($project, $health, $note, $actor): Project {
            $previous = $project->health()->first();

            $project->forceFill(['health_status_id' => $health->id, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.health_changed',
                description: 'Mengubah health project menjadi '.$health->name,
                subject: $project,
                old: ['health' => $previous?->name],
                new: ['health' => $health->name],
                properties: array_filter(['note' => $note]),
            );

            return $project;
        });
    }
}
