<?php

namespace App\Domain\Project\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared invariants for project write actions.
 */
class ProjectGuard
{
    public function ensureEditable(Project $project): void
    {
        if ($project->isArchived()) {
            throw new BusinessRuleException('Project sudah diarsipkan dan bersifat read-only. Pulihkan dari arsip untuk mengubahnya.');
        }
    }

    public function activeUser(int $id, string $label): User
    {
        $user = User::query()->find($id);

        if ($user === null || ! $user->is_active) {
            throw new BusinessRuleException($label.' harus user yang aktif.');
        }

        return $user;
    }

    public function activeDepartment(int $id): Department
    {
        $department = Department::query()->find($id);

        if ($department === null || ! $department->is_active) {
            throw new BusinessRuleException('Department nonaktif tidak dapat ditambahkan ke project.');
        }

        return $department;
    }

    /**
     * New or changed references must point at active master records.
     *
     * @param  class-string<Model>  $model
     */
    public function activeMaster(string $model, ?int $id, ?int $currentId, string $label): void
    {
        if ($id === null || $id === $currentId) {
            return;
        }

        if (! $model::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException($label.' nonaktif tidak dapat dipilih.');
        }
    }
}
