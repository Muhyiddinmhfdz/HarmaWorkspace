<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeProjectOwner
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function handle(Project $project, ?int $ownerId, User $actor): Project
    {
        if ($project->owner_id === $ownerId) {
            return $project;
        }

        $this->guard->ensureEditable($project);
        $owner = $ownerId !== null ? $this->guard->activeUser($ownerId, 'Project Owner') : null;

        return DB::transaction(function () use ($project, $owner, $actor): Project {
            $previous = $project->owner()->first();

            $project->forceFill(['owner_id' => $owner?->id, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.owner_changed',
                description: $owner ? 'Mengganti Project Owner menjadi '.$owner->name : 'Menghapus Project Owner',
                subject: $project,
                old: ['owner' => $previous?->name],
                new: ['owner' => $owner?->name],
            );

            return $project;
        });
    }
}
