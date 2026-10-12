<?php

namespace App\Domain\Task\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\User;

/**
 * Invariants shared by task write actions.
 */
class TaskGuard
{
    /**
     * Tasks of an archived or finished (terminal) project are read-only.
     */
    public function ensureEditable(Task $task): void
    {
        if ($task->project_id !== null) {
            $this->ensureProjectAcceptsWork($task->project()->with('status')->firstOrFail());
        }
    }

    public function ensureProjectAcceptsWork(Project $project): void
    {
        $project->loadMissing('status');

        if ($project->isArchived()) {
            throw new BusinessRuleException('Project sudah diarsipkan. Task di dalamnya bersifat read-only.');
        }

        if ($project->status->is_terminal) {
            throw new BusinessRuleException('Project sudah berakhir ('.$project->status->name.'). Task di dalamnya tidak dapat diubah.');
        }
    }

    /**
     * Project tasks: the department must be involved in the project.
     */
    public function department(?int $departmentId, ?Project $project, ?int $currentId = null): ?Department
    {
        if ($departmentId === null) {
            return null;
        }

        $department = Department::query()->find($departmentId);

        if ($department === null || (! $department->is_active && $departmentId !== $currentId)) {
            throw new BusinessRuleException('Department nonaktif tidak dapat dipilih.');
        }

        if ($project !== null && ! $project->departments()->where('department_id', $departmentId)->exists()) {
            throw new BusinessRuleException('Department '.$department->name.' tidak terlibat di Project ini. Tambahkan ke Project terlebih dahulu.');
        }

        return $department;
    }

    public function milestone(?int $milestoneId, ?Project $project): ?ProjectMilestone
    {
        if ($milestoneId === null) {
            return null;
        }

        $milestone = $project?->milestones()->find($milestoneId);

        if ($milestone === null) {
            throw new BusinessRuleException('Milestone tidak berasal dari Project yang dipilih.');
        }

        return $milestone;
    }

    /**
     * Project tasks may only be assigned to active project members or the
     * project manager. Nobody is added to a project implicitly (ADR-042).
     */
    public function assignee(int $userId, ?Project $project): User
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new BusinessRuleException('User yang dipilih harus user aktif.');
        }

        if ($project !== null
            && $project->project_manager_id !== $user->id
            && ! $project->members()->where('user_id', $user->id)->exists()) {
            throw new BusinessRuleException($user->name.' belum menjadi anggota Project ini. Tambahkan sebagai Project Member terlebih dahulu.');
        }

        return $user;
    }
}
