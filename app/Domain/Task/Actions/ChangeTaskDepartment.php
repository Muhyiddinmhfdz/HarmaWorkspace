<?php

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeTaskDepartment
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    public function handle(Task $task, ?int $departmentId, User $actor): Task
    {
        if ($task->department_id === $departmentId) {
            return $task;
        }

        $this->guard->ensureEditable($task);
        $department = $this->guard->department($departmentId, $task->loadMissing('project')->project, $task->department_id);

        if ($department === null && ! $task->picAssignment()->exists()) {
            throw new BusinessRuleException('Task harus memiliki Department atau PIC agar jelas siapa yang bertanggung jawab.');
        }

        return DB::transaction(function () use ($task, $department, $actor): Task {
            $previous = $task->department()->value('name');
            $task->forceFill(['department_id' => $department?->id, 'updated_by' => $actor->id])->save();

            $this->audit->record($task, 'task.department_changed', 'Mengubah department task menjadi '.($department->name ?? 'tanpa department'),
                old: ['department' => $previous], new: ['department' => $department?->name]);

            return $task;
        });
    }
}
