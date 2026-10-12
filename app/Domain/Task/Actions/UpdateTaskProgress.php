<?php

namespace App\Domain\Task\Actions;

use App\Domain\Project\Actions\RecalculateProjectProgress;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual task progress (ADR-043). Finished tasks keep their progress.
 */
class UpdateTaskProgress
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
        private RecalculateProjectProgress $recalculate,
    ) {}

    public function handle(Task $task, int $percentage, User $actor): Task
    {
        $this->guard->ensureEditable($task);

        if ($percentage < 0 || $percentage > 100) {
            throw new BusinessRuleException('Progress harus di antara 0 dan 100.');
        }

        return DB::transaction(function () use ($task, $percentage, $actor): Task {
            $task = Task::query()->with('status')->lockForUpdate()->findOrFail($task->id);

            if ($task->status->is_terminal) {
                throw new BusinessRuleException('Task sudah berstatus '.$task->status->name.'. Progress tidak dapat diubah.');
            }

            $previous = $task->progress_percentage;

            if ($previous === $percentage) {
                return $task;
            }

            $task->forceFill(['progress_percentage' => $percentage, 'updated_by' => $actor->id])->save();

            $this->audit->record($task, 'task.progress_changed', 'Mengubah progress task '.$previous.'% → '.$percentage.'%',
                old: ['progress_percentage' => $previous], new: ['progress_percentage' => $percentage]);

            $this->recalculate->handle($task->project_id);

            return $task;
        });
    }
}
