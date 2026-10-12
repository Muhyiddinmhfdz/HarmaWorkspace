<?php

namespace App\Domain\Task\Actions;

use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Models\Task;
use App\Models\TaskSubtask;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Subtasks are one level below a task. Completing them does not change the
 * task progress in Phase 3 (ADR-043).
 */
class ManageTaskSubtasks
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, pic_user_id?: int|null, due_date?: string|null}  $data
     */
    public function create(Task $task, array $data, User $actor): TaskSubtask
    {
        $this->guard->ensureEditable($task);
        $picId = filled($data['pic_user_id'] ?? null) ? $this->guard->assignee((int) $data['pic_user_id'], $task->loadMissing('project')->project)->id : null;

        return DB::transaction(function () use ($task, $data, $actor, $picId): TaskSubtask {
            $subtask = new TaskSubtask([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'pic_user_id' => $picId,
                'due_date' => filled($data['due_date'] ?? null) ? $data['due_date'] : null,
            ]);

            $subtask->forceFill([
                'task_id' => $task->id,
                'sort_order' => ((int) $task->subtasks()->max('sort_order')) + 10,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record($task, 'task.subtask_created', 'Menambahkan subtask "'.$subtask->title.'"');

            return $subtask;
        });
    }

    /**
     * @param  array{title: string, description?: string|null, pic_user_id?: int|null, due_date?: string|null}  $data
     */
    public function update(TaskSubtask $subtask, array $data, User $actor): TaskSubtask
    {
        $task = $subtask->task()->firstOrFail();
        $this->guard->ensureEditable($task);
        $picId = filled($data['pic_user_id'] ?? null) ? (int) $data['pic_user_id'] : null;

        if ($picId !== null && $picId !== $subtask->pic_user_id) {
            $this->guard->assignee($picId, $task->loadMissing('project')->project);
        }

        $subtask->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'pic_user_id' => $picId,
            'due_date' => filled($data['due_date'] ?? null) ? $data['due_date'] : null,
        ]);

        if ($subtask->isDirty()) {
            $subtask->forceFill(['updated_by' => $actor->id])->save();
        }

        return $subtask;
    }

    public function toggle(TaskSubtask $subtask, User $actor): TaskSubtask
    {
        $task = $subtask->task()->firstOrFail();
        $this->guard->ensureEditable($task);

        return DB::transaction(function () use ($subtask, $task, $actor): TaskSubtask {
            $completed = ! $subtask->is_completed;

            $subtask->forceFill([
                'is_completed' => $completed,
                'completed_at' => $completed ? Date::now() : null,
                'completed_by' => $completed ? $actor->id : null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                $task,
                $completed ? 'task.subtask_completed' : 'task.subtask_reopened',
                ($completed ? 'Menyelesaikan subtask "' : 'Membuka kembali subtask "').$subtask->title.'"',
            );

            return $subtask;
        });
    }

    public function remove(TaskSubtask $subtask, User $actor): void
    {
        $task = $subtask->task()->firstOrFail();
        $this->guard->ensureEditable($task);

        DB::transaction(function () use ($subtask, $task): void {
            $subtask->checklistItems()->delete();
            $subtask->delete();

            $this->audit->record($task, 'task.subtask_removed', 'Menghapus subtask "'.$subtask->title.'"', old: ['subtask' => $subtask->title]);
        });
    }
}
