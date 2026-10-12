<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\ProjectDepartment;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Multi-division structure of a project: add, update, remove supporting
 * departments and hand over the lead role. Removed departments keep their
 * record (removed_at) for history.
 */
class ManageProjectDepartments
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function add(Project $project, int $departmentId, ?string $function, ?int $leadUserId): ProjectDepartment
    {
        $this->guard->ensureEditable($project);
        $department = $this->guard->activeDepartment($departmentId);
        $leadUser = $leadUserId !== null ? $this->guard->activeUser($leadUserId, 'Department lead') : null;

        return DB::transaction(function () use ($project, $department, $function, $leadUser): ProjectDepartment {
            Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->departments()->where('department_id', $department->id)->exists()) {
                throw new BusinessRuleException($department->name.' sudah terlibat di project ini.');
            }

            $record = ProjectDepartment::query()->create([
                'project_id' => $project->id,
                'department_id' => $department->id,
                'function' => $function,
                'lead_user_id' => $leadUser?->id,
            ]);

            $this->audit->record(
                event: 'project.department_added',
                description: 'Menambahkan '.$department->name.' sebagai supporting department',
                subject: $project,
                new: ['department' => $department->name, 'function' => $function, 'lead' => $leadUser?->name],
            );

            return $record;
        });
    }

    public function update(ProjectDepartment $record, ?string $function, ?int $leadUserId): ProjectDepartment
    {
        $project = $record->project()->firstOrFail();
        $this->guard->ensureEditable($project);
        $leadUser = $leadUserId !== null && $leadUserId !== $record->lead_user_id
            ? $this->guard->activeUser($leadUserId, 'Department lead')
            : null;

        return DB::transaction(function () use ($record, $project, $function, $leadUserId, $leadUser): ProjectDepartment {
            $record->fill(['function' => $function, 'lead_user_id' => $leadUserId]);

            if (! $record->isDirty()) {
                return $record;
            }

            $old = $record->getOriginal();
            $record->save();

            $this->audit->record(
                event: 'project.department_updated',
                description: 'Memperbarui peran department '.$record->department()->value('name'),
                subject: $project,
                old: ['function' => $old['function'] ?? null, 'lead_user_id' => $old['lead_user_id'] ?? null],
                new: ['function' => $function, 'lead' => $leadUser->name ?? $record->leadUser()->value('name')],
            );

            return $record;
        });
    }

    /**
     * Make another (or a new) department the lead. The previous lead stays as a
     * supporting department, so there is never a moment without a lead.
     */
    public function changeLead(Project $project, int $departmentId): ProjectDepartment
    {
        $this->guard->ensureEditable($project);
        $department = $this->guard->activeDepartment($departmentId);

        return DB::transaction(function () use ($project, $department): ProjectDepartment {
            Project::query()->lockForUpdate()->findOrFail($project->id);

            $current = $project->departments()->where('is_lead', true)->with('department:id,name')->first();

            if ($current?->department_id === $department->id) {
                return $current;
            }

            $current?->forceFill(['is_lead' => false])->save();

            $target = $project->departments()->where('department_id', $department->id)->first()
                ?? ProjectDepartment::query()->create(['project_id' => $project->id, 'department_id' => $department->id]);

            $target->forceFill(['is_lead' => true])->save();

            $this->audit->record(
                event: 'project.lead_department_changed',
                description: 'Mengganti lead department menjadi '.$department->name,
                subject: $project,
                old: ['lead_department' => $current?->department->name],
                new: ['lead_department' => $department->name],
            );

            return $target;
        });
    }

    public function remove(ProjectDepartment $record): void
    {
        $project = $record->project()->firstOrFail();
        $this->guard->ensureEditable($project);

        if ($record->is_lead) {
            throw new BusinessRuleException('Lead department tidak dapat dihapus. Tetapkan lead department lain terlebih dahulu.');
        }

        if ($record->removed_at !== null) {
            return;
        }

        DB::transaction(function () use ($record, $project): void {
            $record->forceFill(['removed_at' => Date::now()])->save();

            $this->audit->record(
                event: 'project.department_removed',
                description: 'Menghapus '.$record->department()->value('name').' dari project',
                subject: $project,
                old: ['department' => $record->department()->value('name')],
            );
        });
    }
}
