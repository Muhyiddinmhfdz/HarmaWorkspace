<?php

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for the task schedule.
 *
 * - No schedule yet: the given dates become the baseline (planned = current).
 * - Existing schedule: only current dates change, a reason is required and
 *   the change is stored in schedule_changes; planned dates stay untouched.
 */
class ChangeTaskSchedule
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    public function handle(Task $task, ?string $start, ?string $end, ?string $reason, User $actor): Task
    {
        $this->guard->ensureEditable($task);

        $newStart = filled($start) ? Carbon::parse($start)->startOfDay() : null;
        $newEnd = filled($end) ? Carbon::parse($end)->startOfDay() : null;

        if ($newStart !== null && $newEnd !== null && $newEnd->lt($newStart)) {
            throw new BusinessRuleException('Deadline tidak boleh lebih awal dari tanggal mulai.');
        }

        $unchanged = $task->current_start?->toDateString() === $newStart?->toDateString()
            && $task->current_end?->toDateString() === $newEnd?->toDateString();

        if ($unchanged) {
            throw new BusinessRuleException('Jadwal baru sama dengan jadwal saat ini.');
        }

        $hasBaseline = $task->planned_start !== null || $task->planned_end !== null;

        if ($hasBaseline && blank($reason)) {
            throw new BusinessRuleException('Alasan perubahan jadwal wajib diisi.');
        }

        return DB::transaction(function () use ($task, $newStart, $newEnd, $reason, $actor, $hasBaseline): Task {
            $oldStart = $task->current_start;
            $oldEnd = $task->current_end;

            $task->scheduleChanges()->create([
                'old_start' => $oldStart,
                'old_end' => $oldEnd,
                'new_start' => $newStart,
                'new_end' => $newEnd,
                'reason' => filled($reason) ? (string) $reason : 'Jadwal awal ditetapkan',
                'changed_by' => $actor->id,
            ]);

            $attributes = ['current_start' => $newStart, 'current_end' => $newEnd, 'updated_by' => $actor->id];

            if (! $hasBaseline) {
                $attributes['planned_start'] = $newStart;
                $attributes['planned_end'] = $newEnd;
            }

            $task->forceFill($attributes)->save();

            $this->audit->record(
                $task,
                'task.schedule_changed',
                'Mengubah deadline task '.$task->number.': '.($oldEnd?->translatedFormat('d M Y') ?? 'belum ada').' → '.($newEnd?->translatedFormat('d M Y') ?? 'tanpa deadline'),
                old: ['current_start' => $oldStart?->toDateString(), 'current_end' => $oldEnd?->toDateString()],
                new: ['current_start' => $newStart?->toDateString(), 'current_end' => $newEnd?->toDateString()],
                properties: ['reason' => $reason],
            );

            return $task;
        });
    }
}
