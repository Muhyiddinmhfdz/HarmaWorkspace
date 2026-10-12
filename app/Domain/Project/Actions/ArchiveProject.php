<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Archive a finished project (terminal status) or restore it. Archived
 * projects are hidden from default lists, stay searchable and read-only.
 */
class ArchiveProject
{
    public function __construct(private AuditLogger $audit) {}

    public function archive(Project $project, User $actor): Project
    {
        if ($project->isArchived()) {
            return $project;
        }

        $project->loadMissing('status');

        if (! $project->status?->is_terminal) {
            throw new BusinessRuleException('Hanya project yang sudah selesai atau dibatalkan yang dapat diarsipkan.');
        }

        return DB::transaction(function () use ($project, $actor): Project {
            $project->forceFill(['archived_at' => Date::now(), 'updated_by' => $actor->id])->save();

            $this->audit->record('project.archived', 'Mengarsipkan project '.$project->name, $project);

            return $project;
        });
    }

    public function restore(Project $project, User $actor): Project
    {
        if (! $project->isArchived()) {
            return $project;
        }

        return DB::transaction(function () use ($project, $actor): Project {
            $project->forceFill(['archived_at' => null, 'updated_by' => $actor->id])->save();

            $this->audit->record('project.restored', 'Memulihkan project '.$project->name.' dari arsip', $project);

            return $project;
        });
    }
}
