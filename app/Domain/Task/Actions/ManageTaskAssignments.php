<?php

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Enums\TaskAssignmentRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * One active Main PIC plus collaborators, with full history: replacing or
 * removing someone ends their assignment row; nothing is deleted (ADR-040).
 */
class ManageTaskAssignments
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    public function setPic(Task $task, int $userId, User $actor): Task
    {
        $this->guard->ensureEditable($task);
        $user = $this->guard->assignee($userId, $task->loadMissing('project')->project);

        return DB::transaction(function () use ($task, $user, $actor): Task {
            Task::query()->lockForUpdate()->findOrFail($task->id);

            $current = $task->picAssignment()->with('user:id,name')->first();

            if ($current?->user_id === $user->id) {
                return $task;
            }

            $now = Date::now();
            $current?->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save();

            // A collaborator promoted to PIC leaves the collaborator role.
            $task->collaboratorAssignments()->where('user_id', $user->id)->get()
                ->each(fn (TaskAssignment $assignment): bool => $assignment->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save());

            TaskAssignment::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'role' => TaskAssignmentRole::Pic,
                'assigned_at' => $now,
                'assigned_by' => $actor->id,
            ]);

            $this->audit->record(
                $task,
                $current ? 'task.pic_changed' : 'task.pic_assigned',
                $current ? 'Mengganti PIC task: '.$current->user->name.' → '.$user->name : 'Menetapkan '.$user->name.' sebagai PIC task',
                old: ['pic' => $current?->user->name],
                new: ['pic' => $user->name],
            );

            return $task;
        });
    }

    public function addCollaborator(Task $task, int $userId, User $actor): TaskAssignment
    {
        $this->guard->ensureEditable($task);
        $user = $this->guard->assignee($userId, $task->loadMissing('project')->project);

        return DB::transaction(function () use ($task, $user, $actor): TaskAssignment {
            Task::query()->lockForUpdate()->findOrFail($task->id);

            $existing = $task->activeAssignments()->where('user_id', $user->id)->first();

            if ($existing !== null) {
                throw new BusinessRuleException($existing->role === TaskAssignmentRole::Pic
                    ? $user->name.' sudah menjadi PIC task ini.'
                    : $user->name.' sudah menjadi kolaborator task ini.');
            }

            $assignment = TaskAssignment::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'role' => TaskAssignmentRole::Collaborator,
                'assigned_at' => Date::now(),
                'assigned_by' => $actor->id,
            ]);

            $this->audit->record($task, 'task.collaborator_added', 'Menambahkan '.$user->name.' sebagai kolaborator', new: ['collaborator' => $user->name]);

            return $assignment;
        });
    }

    public function removeCollaborator(Task $task, int $assignmentId, User $actor): void
    {
        $this->guard->ensureEditable($task);

        $assignment = $task->collaboratorAssignments()->with('user:id,name')->findOrFail($assignmentId);

        DB::transaction(function () use ($task, $assignment, $actor): void {
            $assignment->forceFill(['ended_at' => Date::now(), 'ended_by' => $actor->id])->save();

            $this->audit->record($task, 'task.collaborator_removed', 'Mengeluarkan '.$assignment->user->name.' dari kolaborator', old: ['collaborator' => $assignment->user->name]);
        });
    }
}
