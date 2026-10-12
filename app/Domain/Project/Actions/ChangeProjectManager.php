<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeProjectManager
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function handle(Project $project, int $managerId, User $actor): Project
    {
        if ($project->project_manager_id === $managerId) {
            return $project;
        }

        $this->guard->ensureEditable($project);
        $manager = $this->guard->activeUser($managerId, 'Project Manager');

        return DB::transaction(function () use ($project, $manager, $actor): Project {
            $previous = $project->projectManager()->first();

            $project->forceFill(['project_manager_id' => $manager->id, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.manager_changed',
                description: 'Mengganti Project Manager menjadi '.$manager->name,
                subject: $project,
                old: ['project_manager' => $previous?->name],
                new: ['project_manager' => $manager->name],
            );

            return $project;
        });
    }
}
