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
 * Work estimate (hours) and optional weight override. Both feed the effective
 * weight (TaskWeight), so project progress is recalculated.
 */
class ChangeTaskEstimate
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
        private RecalculateProjectProgress $recalculate,
    ) {}

    public function handle(Task $task, ?float $estimatedHours, ?float $weight, User $actor): Task
    {
        if ($estimatedHours !== null && $estimatedHours < 0) {
            throw new BusinessRuleException('Work estimate tidak boleh negatif.');
        }

        if ($weight !== null && $weight <= 0) {
            throw new BusinessRuleException('Weight override harus lebih dari 0.');
        }

        $sameEstimate = ($task->estimated_hours === null ? null : (float) $task->estimated_hours) === $estimatedHours;
        $sameWeight = ($task->weight === null ? null : (float) $task->weight) === $weight;

        if ($sameEstimate && $sameWeight) {
            return $task;
        }

        $this->guard->ensureEditable($task);

        return DB::transaction(function () use ($task, $estimatedHours, $weight, $actor): Task {
            $old = ['estimated_hours' => $task->estimated_hours, 'weight' => $task->weight];

            $task->forceFill(['estimated_hours' => $estimatedHours, 'weight' => $weight, 'updated_by' => $actor->id])->save();

            $this->audit->record($task, 'task.weight_changed', 'Mengubah estimasi/bobot task', old: $old,
                new: ['estimated_hours' => $task->estimated_hours, 'weight' => $task->weight]);

            $this->recalculate->handle($task->project_id);

            return $task;
        });
    }
}
