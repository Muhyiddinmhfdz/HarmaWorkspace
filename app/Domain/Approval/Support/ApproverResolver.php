<?php

namespace App\Domain\Approval\Support;

use App\Enums\ApprovalDecisionType;
use App\Enums\ApproverType;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Finds the approvers of a step (ADR-070): the approver RULE is snapshotted
 * in the step; the eligible PEOPLE are resolved live, so the current
 * department leader or project manager can decide after organisational
 * changes. Approvers designated by the step (specific user, department
 * leader, project role, project manager/owner, requester, creator) may decide
 * without access to the full record — they see the request summary only.
 * Permission-based approvers are an open set and must also be able to see
 * the record. The requester cannot approve unless the step allows it.
 */
class ApproverResolver
{
    public function isEligible(User $user, ApprovalStep $step, ApprovalRequest $request, Model $approvable): bool
    {
        if (! $user->is_active || ! $this->matches($user, $step, $approvable)) {
            return false;
        }

        if (! $step->allow_self_approval && $request->requested_by === $user->id) {
            return false;
        }

        if ($step->decisions()->where('user_id', $user->id)->exists()) {
            return false;
        }

        return $step->approver_type !== ApproverType::Permission || $user->can('view', $approvable);
    }

    /**
     * Whether the user fits the step's approver rule (ignores self approval
     * and earlier decisions).
     */
    public function matches(User $user, ApprovalStep $step, Model $approvable): bool
    {
        $config = $step->approver_context ?? [];

        return match ($step->approver_type) {
            ApproverType::SpecificUser => (int) ($config['user_id'] ?? 0) === $user->id,
            ApproverType::DepartmentLeader => ($department = $this->department($approvable, $config)) !== null && $user->leadsDepartment($department),
            ApproverType::ProjectManager => $this->project($approvable)?->project_manager_id === $user->id,
            ApproverType::ProjectOwner => ($owner = $this->project($approvable)?->owner_id) !== null && $owner === $user->id,
            ApproverType::ProjectRole => ($project = $this->project($approvable)) !== null
                && $project->members()->where('user_id', $user->id)->where('project_role_id', (int) ($config['project_role_id'] ?? 0))->exists(),
            ApproverType::Requester => $this->requesterId($approvable) === $user->id,
            ApproverType::Creator => $approvable->getAttribute('created_by') === $user->id,
            ApproverType::Permission => isset($config['permission']) && is_string($config['permission']) && $user->can($config['permission']),
        };
    }

    /**
     * Possible approvers (active users), or null when the rule is an open set
     * (permission based). An empty collection means "approver unavailable".
     *
     * @return Collection<int, User>|null
     */
    public function candidates(ApprovalStep $step, ApprovalRequest $request, Model $approvable): ?Collection
    {
        $config = $step->approver_context ?? [];

        $ids = match ($step->approver_type) {
            ApproverType::SpecificUser => [(int) ($config['user_id'] ?? 0)],
            ApproverType::DepartmentLeader => ($department = $this->department($approvable, $config)) !== null
                ? DepartmentMembership::query()->where('department_id', $department)->where('is_leader', true)->whereNull('ended_at')->pluck('user_id')->all()
                : [],
            ApproverType::ProjectManager => [$this->project($approvable)?->project_manager_id],
            ApproverType::ProjectOwner => [$this->project($approvable)?->owner_id],
            ApproverType::ProjectRole => $this->project($approvable)?->members()->where('project_role_id', (int) ($config['project_role_id'] ?? 0))->pluck('user_id')->all() ?? [],
            ApproverType::Requester => [$this->requesterId($approvable)],
            ApproverType::Creator => [$approvable->getAttribute('created_by')],
            ApproverType::Permission => null,
        };

        if ($ids === null) {
            return null;
        }

        $decided = $step->decisions()->pluck('user_id')->all();

        return User::query()
            ->active()
            ->whereIn('id', array_filter($ids))
            ->when(! $step->allow_self_approval, fn ($query) => $query->whereKeyNot($request->requested_by))
            ->whereNotIn('id', $decided)
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'is_active']);
    }

    /**
     * Friendly description of who decides ("Leader of Programmer").
     */
    public function describe(ApprovalStep $step, ?Model $approvable = null): string
    {
        $config = $step->approver_context ?? [];

        return match ($step->approver_type) {
            ApproverType::SpecificUser => User::query()->whereKey((int) ($config['user_id'] ?? 0))->value('name') ?? 'User tidak ditemukan',
            ApproverType::DepartmentLeader => 'Leader '.(($id = $approvable ? $this->department($approvable, $config) : ($config['department_id'] ?? null)) !== null
                ? (Department::query()->whereKey($id)->value('name') ?? 'department')
                : 'department terkait'),
            ApproverType::ProjectRole => 'Project role: '.(ProjectRole::query()->whereKey((int) ($config['project_role_id'] ?? 0))->value('name') ?? '—'),
            ApproverType::Permission => 'Pengguna dengan izin '.($config['permission'] ?? '—'),
            default => $step->approver_type->label(),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function department(Model $approvable, array $config): ?int
    {
        if (isset($config['department_id']) && is_numeric($config['department_id'])) {
            return (int) $config['department_id'];
        }

        return match (true) {
            $approvable instanceof Ticket => $approvable->current_department_id,
            $approvable instanceof Task => $approvable->department_id ?? $this->project($approvable)?->leadDepartment()->value('department_id'),
            $approvable instanceof Project => $approvable->leadDepartment()->value('department_id'),
            default => null,
        };
    }

    private function project(Model $approvable): ?Project
    {
        return match (true) {
            $approvable instanceof Project => $approvable,
            $approvable instanceof Task, $approvable instanceof Ticket => $approvable->loadMissing('project')->project,
            default => null,
        };
    }

    private function requesterId(Model $approvable): ?int
    {
        return match (true) {
            $approvable instanceof Ticket => $approvable->requester_user_id,
            $approvable instanceof Task => $approvable->created_by,
            $approvable instanceof Project => $approvable->owner_id ?? $approvable->project_manager_id,
            default => null,
        };
    }

    public function approvalsOn(ApprovalStep $step): int
    {
        return $step->decisions()->where('decision', ApprovalDecisionType::Approve->value)->count();
    }
}
