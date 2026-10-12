<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectRole;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Explicit project membership with contextual project roles. Removing a
 * member stamps ended_at; rejoining creates a new membership row.
 */
class ManageProjectMembers
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function add(Project $project, int $userId, int $roleId, ?int $departmentId): ProjectMember
    {
        $this->guard->ensureEditable($project);
        $user = $this->guard->activeUser($userId, 'Anggota project');
        $role = $this->activeRole($roleId);
        $department = $departmentId !== null ? Department::query()->findOrFail($departmentId) : null;

        return DB::transaction(function () use ($project, $user, $role, $department): ProjectMember {
            Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->members()->where('user_id', $user->id)->exists()) {
                throw new BusinessRuleException($user->name.' sudah menjadi anggota project ini.');
            }

            $member = ProjectMember::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'project_role_id' => $role->id,
                'department_id' => $department?->id,
                'joined_at' => Date::today(),
            ]);

            $this->audit->record(
                event: 'project.member_added',
                description: 'Menambahkan '.$user->name.' sebagai '.$role->name,
                subject: $project,
                new: ['member' => $user->name, 'role' => $role->name, 'department' => $department?->name],
            );

            return $member;
        });
    }

    public function changeRole(ProjectMember $member, int $roleId): ProjectMember
    {
        $project = $member->project()->firstOrFail();
        $this->guard->ensureEditable($project);

        if ($member->ended_at !== null) {
            throw new BusinessRuleException('Anggota ini sudah keluar dari project.');
        }

        if ($member->project_role_id === $roleId) {
            return $member;
        }

        $role = $this->activeRole($roleId);

        return DB::transaction(function () use ($member, $project, $role): ProjectMember {
            $previous = $member->role()->value('name');
            $member->forceFill(['project_role_id' => $role->id])->save();
            $name = $member->user()->value('name');

            $this->audit->record(
                event: 'project.member_role_changed',
                description: 'Mengubah peran '.$name.' menjadi '.$role->name,
                subject: $project,
                old: ['member' => $name, 'role' => $previous],
                new: ['member' => $name, 'role' => $role->name],
            );

            return $member;
        });
    }

    public function remove(ProjectMember $member): void
    {
        $project = $member->project()->firstOrFail();
        $this->guard->ensureEditable($project);

        if ($member->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($member, $project): void {
            $member->forceFill(['ended_at' => Date::today()])->save();
            $name = $member->user()->value('name');

            $this->audit->record(
                event: 'project.member_removed',
                description: 'Mengeluarkan '.$name.' dari project',
                subject: $project,
                old: ['member' => $name, 'role' => $member->role()->value('name')],
            );
        });
    }

    private function activeRole(int $roleId): ProjectRole
    {
        return ProjectRole::query()->active()->find($roleId)
            ?? throw new BusinessRuleException('Project role yang dipilih tidak tersedia.');
    }
}
