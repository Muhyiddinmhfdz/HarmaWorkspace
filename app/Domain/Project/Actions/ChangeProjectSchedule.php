<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for the current schedule. The planned (baseline)
 * schedule never changes here; every change is stored in schedule_changes
 * with a mandatory reason.
 */
class ChangeProjectSchedule
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    public function handle(Project $project, string $start, string $end, string $reason, User $actor): Project
    {
        $this->guard->ensureEditable($project);

        $newStart = Carbon::parse($start)->startOfDay();
        $newEnd = Carbon::parse($end)->startOfDay();

        if ($newEnd->lt($newStart)) {
            throw new BusinessRuleException('Deadline baru tidak boleh sebelum tanggal mulai.');
        }

        if (trim($reason) === '') {
            throw new BusinessRuleException('Alasan perubahan jadwal wajib diisi.');
        }

        return DB::transaction(function () use ($project, $newStart, $newEnd, $reason, $actor): Project {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->current_start->equalTo($newStart) && $project->current_end->equalTo($newEnd)) {
                throw new BusinessRuleException('Jadwal baru sama dengan jadwal saat ini.');
            }

            $oldStart = $project->current_start;
            $oldEnd = $project->current_end;

            $project->scheduleChanges()->create([
                'old_start' => $oldStart,
                'old_end' => $oldEnd,
                'new_start' => $newStart,
                'new_end' => $newEnd,
                'reason' => $reason,
                'changed_by' => $actor->id,
            ]);

            $project->forceFill(['current_start' => $newStart, 'current_end' => $newEnd, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.schedule_changed',
                description: 'Mengubah jadwal project: deadline '.$oldEnd->translatedFormat('d M Y').' → '.$newEnd->translatedFormat('d M Y'),
                subject: $project,
                old: ['current_start' => $oldStart->toDateString(), 'current_end' => $oldEnd->toDateString()],
                new: ['current_start' => $newStart->toDateString(), 'current_end' => $newEnd->toDateString()],
                properties: ['reason' => $reason],
            );

            return $project;
        });
    }
}
