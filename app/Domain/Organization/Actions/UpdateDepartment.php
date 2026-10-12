<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateDepartment
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, parent_id?: int|null, sort_order?: int}  $data
     */
    public function handle(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data): Department {
            if ($department->isCodeLocked() && Str::upper(trim($data['code'])) !== $department->code) {
                throw new BusinessRuleException('Code tidak dapat diubah karena department sudah digunakan.');
            }

            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null && $parentId !== $department->parent_id) {
                if (in_array((int) $parentId, $department->selfAndDescendantIds(), true)) {
                    throw new BusinessRuleException('Parent department tidak boleh department itu sendiri atau sub-department-nya.');
                }

                if (! Department::query()->whereKey($parentId)->active()->exists()) {
                    throw new BusinessRuleException('Parent department harus department yang aktif.');
                }
            }

            $department->update($data);

            $this->audit->recordChanges('department.updated', 'Mengubah department '.$department->name, $department);

            return $department;
        });
    }
}
