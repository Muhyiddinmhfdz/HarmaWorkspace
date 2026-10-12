<?php

namespace App\Domain\Task\Actions;

use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Actions\RecalculateProjectProgress;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Domain\Task\Support\TaskLifecycle;
use App\Enums\MasterScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for task status, along TaskLifecycle. Side effects
 * (ADR-043/049) are driven by status flags:
 *
 * - started status, no actual start yet → actual_start = today;
 * - blocked status → reason required, blocker metadata stored;
 *   leaving it clears the blocker (history stays in the activity log);
 * - completed status → progress 100, actual_end = today, completed_at = now
 *   (actual_start defaults to the same day when work was never started);
 * - leaving a completed status (reopen) → actual_end/completed_at cleared,
 *   progress kept; the completion remains in the activity log;
 * - cancelled → excluded from project progress.
 */
class ChangeTaskStatus
{
    public function __construct(
        private ScopedMasterGuard $masters,
        private TaskGuard $guard,
        private TaskLifecycle $lifecycle,
        private TaskAudit $audit,
        private RecalculateProjectProgress $recalculate,
    ) {}

    public function handle(Task $task, int $statusId, User $actor, ?string $reason = null): Task
    {
        $this->guard->ensureEditable($task);
        $target = $this->masters->status($statusId, MasterScope::Task);

        return DB::transaction(function () use ($task, $target, $actor, $reason): Task {
            $task = Task::query()->with('status')->lockForUpdate()->findOrFail($task->id);
            $from = $task->status;

            if (! $this->lifecycle->allows($from, $target)) {
                throw new BusinessRuleException($this->lifecycle->rejectionMessage($from, $target));
            }

            if ($target->is_blocked && blank($reason)) {
                throw new BusinessRuleException('Alasan blocker wajib diisi.');
            }

            $today = Date::today();
            $attributes = ['status_id' => $target->id, 'updated_by' => $actor->id];

            if ($target->counts_as_started && $task->actual_start === null) {
                $attributes['actual_start'] = $today;
            }

            if ($target->is_blocked) {
                $attributes += ['blocked_reason' => $reason, 'blocked_at' => Date::now(), 'blocked_by' => $actor->id];
            } elseif ($from->is_blocked) {
                $attributes += ['blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null];
            }

            if ($target->counts_as_completed) {
                $attributes += [
                    'progress_percentage' => 100,
                    'actual_start' => $task->actual_start ?? $today,
                    'actual_end' => $today,
                    'completed_at' => Date::now(),
                ];
            } elseif ($from->counts_as_completed) {
                $attributes += ['actual_end' => null, 'completed_at' => null];
            }

            $task->forceFill($attributes)->save();

            [$event, $description] = match (true) {
                $target->is_blocked => ['task.blocked', 'Menandai task '.$task->number.' blocked: '.$reason],
                $from->is_blocked => ['task.unblocked', 'Melanjutkan task '.$task->number.' ('.$from->name.' → '.$target->name.')'],
                $target->counts_as_completed => ['task.completed', 'Menyelesaikan task '.$task->number.' '.$task->title],
                $target->is_terminal => ['task.cancelled', 'Membatalkan task '.$task->number.' '.$task->title],
                $from->is_terminal => ['task.reopened', 'Membuka kembali task '.$task->number.' ('.$from->name.' → '.$target->name.')'],
                default => ['task.status_changed', 'Mengubah status task '.$task->number.' menjadi '.$target->name],
            };

            $this->audit->record($task, $event, $description,
                old: ['status' => $from->name], new: ['status' => $target->name],
                properties: ['reason' => $target->is_blocked ? $reason : null]);

            $this->recalculate->handle($task->project_id);

            return $task;
        });
    }
}
