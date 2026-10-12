<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Replace a user's active department memberships with the given list.
 *
 * Removed memberships are ended (history kept), not deleted. The database
 * enforces one active primary per user; this action enforces the rest.
 */
class SyncUserMemberships
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<array{department_id: int|string, position_id?: int|string|null, is_leader?: bool, is_primary?: bool}>  $memberships
     */
    public function handle(User $user, array $memberships): void
    {
        $desired = $this->normalise($memberships);

        DB::transaction(function () use ($user, $desired): void {
            $current = $user->activeMemberships()->lockForUpdate()->get()->keyBy('department_id');

            $this->guardMasters($desired, $current);

            $before = $this->summarise($current);

            // Clear primary flags first so the unique primary index never sees two at once.
            $current->where('is_primary', true)->each(
                fn (DepartmentMembership $membership): bool => $membership->forceFill(['is_primary' => false])->save(),
            );

            $today = Date::today();

            foreach ($current as $departmentId => $membership) {
                if (! $desired->has($departmentId)) {
                    $membership->forceFill(['ended_at' => $today, 'is_primary' => false])->save();
                }
            }

            foreach ($desired as $departmentId => $attributes) {
                $membership = $current->get($departmentId) ?? new DepartmentMembership([
                    'user_id' => $user->id,
                    'department_id' => $departmentId,
                    'joined_at' => $today,
                ]);

                $membership->fill($attributes)->save();
            }

            $after = $this->summarise($user->activeMemberships()->get());

            if ($before !== $after) {
                $this->audit->record(
                    event: 'user.memberships_changed',
                    description: 'Mengubah department user '.$user->name,
                    subject: $user,
                    old: ['memberships' => $before],
                    new: ['memberships' => $after],
                );
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $memberships
     * @return Collection<int, array{position_id: int|null, is_leader: bool, is_primary: bool}>
     */
    private function normalise(array $memberships): Collection
    {
        $desired = collect($memberships)
            ->mapWithKeys(fn (array $row): array => [
                (int) $row['department_id'] => [
                    'position_id' => filled($row['position_id'] ?? null) ? (int) $row['position_id'] : null,
                    'is_leader' => (bool) ($row['is_leader'] ?? false),
                    'is_primary' => (bool) ($row['is_primary'] ?? false),
                ],
            ]);

        if ($desired->count() !== count($memberships)) {
            throw new BusinessRuleException('Department yang sama tidak boleh dipilih lebih dari sekali.');
        }

        $primaryCount = $desired->where('is_primary', true)->count();

        if ($desired->isNotEmpty() && $primaryCount !== 1) {
            throw new BusinessRuleException('Pilih tepat satu primary department.');
        }

        return $desired;
    }

    /**
     * New memberships and changed positions must reference active masters.
     * Existing memberships in a since-deactivated department may stay.
     *
     * @param  Collection<int, array{position_id: int|null, is_leader: bool, is_primary: bool}>  $desired
     * @param  EloquentCollection<int, DepartmentMembership>  $current
     */
    private function guardMasters(Collection $desired, EloquentCollection $current): void
    {
        $newDepartmentIds = $desired->keys()->diff($current->keys());

        if ($newDepartmentIds->isNotEmpty()
            && Department::query()->whereKey($newDepartmentIds)->active()->count() !== $newDepartmentIds->count()) {
            throw new BusinessRuleException('Department nonaktif tidak dapat dipilih untuk keanggotaan baru.');
        }

        $changedPositionIds = $desired
            ->filter(fn (array $row, int $departmentId): bool => $row['position_id'] !== null
                && $row['position_id'] !== $current->get($departmentId)?->position_id)
            ->pluck('position_id')
            ->unique();

        if ($changedPositionIds->isNotEmpty()
            && Position::query()->whereKey($changedPositionIds)->active()->count() !== $changedPositionIds->count()) {
            throw new BusinessRuleException('Position nonaktif tidak dapat dipilih.');
        }
    }

    /**
     * Comparable, human-readable snapshot for the audit log.
     *
     * @param  EloquentCollection<int, DepartmentMembership>  $memberships
     * @return list<array{department: string, position: string|null, primary: bool, leader: bool}>
     */
    private function summarise(EloquentCollection $memberships): array
    {
        $memberships->loadMissing(['department:id,code', 'position:id,code']);

        return array_values($memberships
            ->sortBy('department_id')
            ->map(fn (DepartmentMembership $membership): array => [
                'department' => $membership->department->code,
                'position' => $membership->position?->code,
                'primary' => $membership->is_primary,
                'leader' => $membership->is_leader,
            ])
            ->all());
    }
}
