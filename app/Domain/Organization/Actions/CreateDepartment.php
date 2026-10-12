<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

class CreateDepartment
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, parent_id?: int|null, sort_order?: int}  $data
     */
    public function handle(array $data): Department
    {
        return DB::transaction(function () use ($data): Department {
            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null && ! Department::query()->whereKey($parentId)->active()->exists()) {
                throw new BusinessRuleException('Parent department harus department yang aktif.');
            }

            $department = Department::create($data);

            $this->audit->recordCreated('department.created', 'Membuat department '.$department->name, $department);

            return $department;
        });
    }
}
