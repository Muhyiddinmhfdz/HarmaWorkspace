<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Project\Support\VisibilityGuard;
use App\Enums\ProjectVisibility;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for project visibility (a sensitive access setting).
 */
class ChangeProjectVisibility
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
        private VisibilityGuard $visibilityGuard,
    ) {}

    public function handle(Project $project, ProjectVisibility $visibility, User $actor): Project
    {
        if ($project->visibility === $visibility) {
            return $project;
        }

        $this->guard->ensureEditable($project);
        $this->visibilityGuard->ensureCanSet($visibility, $actor);

        return DB::transaction(function () use ($project, $visibility, $actor): Project {
            $previous = $project->visibility;

            $project->forceFill(['visibility' => $visibility, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.visibility_changed',
                description: 'Mengubah visibility project dari '.$previous->label().' menjadi '.$visibility->label(),
                subject: $project,
                old: ['visibility' => $previous->value],
                new: ['visibility' => $visibility->value],
            );

            return $project;
        });
    }
}
