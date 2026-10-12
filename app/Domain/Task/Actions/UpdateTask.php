<?php

namespace App\Domain\Task\Actions;

use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Enums\MasterScope;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update general task information (title, description, type, category,
 * priority, milestone). Department, estimate/weight, schedule, status,
 * progress and assignment have their own actions. The project of a task
 * cannot be changed (ADR-048).
 */
class UpdateTask
{
    public function __construct(
        private ScopedMasterGuard $masters,
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, task_type_id?: int|null, task_category_id?: int|null, priority_id: int, milestone_id?: int|null}  $data
     */
    public function handle(Task $task, array $data, User $actor): Task
    {
        $this->guard->ensureEditable($task);

        $priorityId = (int) $data['priority_id'];
        $this->masters->priority($priorityId, MasterScope::Task, requireActive: $priorityId !== $task->priority_id);

        foreach ([[TaskType::class, 'task_type_id', 'Task type'], [TaskCategory::class, 'task_category_id', 'Task category']] as [$model, $field, $label]) {
            $id = filled($data[$field] ?? null) ? (int) $data[$field] : null;

            if ($id !== null && $id !== $task->{$field} && ! $model::query()->whereKey($id)->where('is_active', true)->exists()) {
                throw new BusinessRuleException($label.' nonaktif tidak dapat dipilih.');
            }
        }

        $milestoneId = filled($data['milestone_id'] ?? null) ? (int) $data['milestone_id'] : null;

        if ($milestoneId !== $task->milestone_id) {
            $this->guard->milestone($milestoneId, $task->loadMissing('project')->project);
        }

        return DB::transaction(function () use ($task, $data, $actor, $priorityId, $milestoneId): Task {
            $task->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'task_type_id' => filled($data['task_type_id'] ?? null) ? (int) $data['task_type_id'] : null,
                'task_category_id' => filled($data['task_category_id'] ?? null) ? (int) $data['task_category_id'] : null,
                'priority_id' => $priorityId,
            ]);
            $task->milestone_id = $milestoneId;

            if (! $task->isDirty()) {
                return $task;
            }

            $task->forceFill(['updated_by' => $actor->id])->save();

            $changes = $task->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);

            $this->audit->record(
                $task,
                array_key_exists('priority_id', $changes) && count($changes) === 1 ? 'task.priority_changed' : 'task.updated',
                'Memperbarui task '.$task->number,
                old: array_intersect_key($task->getPrevious(), $changes),
                new: $changes,
            );

            return $task;
        });
    }
}
