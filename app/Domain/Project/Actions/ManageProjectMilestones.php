<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Milestones: important project targets. The planned date is fixed when the
 * milestone is created; due-date changes go to schedule_changes.
 */
class ManageProjectMilestones
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, owner_id?: int|null, is_key_milestone?: bool, due_date: string}  $data
     */
    public function create(Project $project, array $data, User $actor): ProjectMilestone
    {
        $this->guard->ensureEditable($project);
        $due = Carbon::parse($data['due_date'])->startOfDay();
        $ownerId = $this->owner($data['owner_id'] ?? null, null);

        return DB::transaction(function () use ($project, $data, $actor, $due, $ownerId): ProjectMilestone {
            $milestone = new ProjectMilestone([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $ownerId,
                'is_key_milestone' => (bool) ($data['is_key_milestone'] ?? false),
                'sort_order' => ((int) $project->milestones()->max('sort_order')) + 10,
            ]);

            $milestone->forceFill([
                'project_id' => $project->id,
                'planned_date' => $due,
                'current_due_date' => $due,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                event: 'project.milestone_created',
                description: 'Menambahkan milestone '.$milestone->name.' (target '.$due->translatedFormat('d M Y').')',
                subject: $project,
                new: ['milestone' => $milestone->name, 'due_date' => $due->toDateString()],
            );

            return $milestone;
        });
    }

    /**
     * @param  array{name: string, description?: string|null, owner_id?: int|null, is_key_milestone?: bool, due_date: string, reason?: string|null}  $data
     */
    public function update(ProjectMilestone $milestone, array $data, User $actor): ProjectMilestone
    {
        $project = $milestone->project()->firstOrFail();
        $this->guard->ensureEditable($project);
        $due = Carbon::parse($data['due_date'])->startOfDay();
        $ownerId = $this->owner($data['owner_id'] ?? null, $milestone->owner_id);
        $dueChanged = ! $milestone->current_due_date->equalTo($due);

        if ($dueChanged && blank($data['reason'] ?? null)) {
            throw new BusinessRuleException('Alasan wajib diisi saat target milestone berubah.');
        }

        return DB::transaction(function () use ($milestone, $project, $data, $actor, $due, $ownerId, $dueChanged): ProjectMilestone {
            $oldDue = $milestone->current_due_date;

            $milestone->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $ownerId,
                'is_key_milestone' => (bool) ($data['is_key_milestone'] ?? false),
            ]);

            if ($dueChanged) {
                $milestone->scheduleChanges()->create([
                    'old_end' => $oldDue,
                    'new_end' => $due,
                    'reason' => (string) ($data['reason'] ?? ''),
                    'changed_by' => $actor->id,
                ]);
                $milestone->current_due_date = $due;
            }

            if (! $milestone->isDirty()) {
                return $milestone;
            }

            $milestone->forceFill(['updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.milestone_updated',
                description: $dueChanged
                    ? 'Mengubah target milestone '.$milestone->name.': '.$oldDue->translatedFormat('d M Y').' → '.$due->translatedFormat('d M Y')
                    : 'Memperbarui milestone '.$milestone->name,
                subject: $project,
                old: $dueChanged ? ['due_date' => $oldDue->toDateString()] : [],
                new: $dueChanged ? ['due_date' => $due->toDateString()] : ['milestone' => $milestone->name],
                properties: array_filter(['reason' => $data['reason'] ?? null]),
            );

            return $milestone;
        });
    }

    public function complete(ProjectMilestone $milestone, string $actualDate, User $actor): ProjectMilestone
    {
        $project = $milestone->project()->firstOrFail();
        $this->guard->ensureEditable($project);
        $date = Carbon::parse($actualDate)->startOfDay();

        if ($date->isFuture()) {
            throw new BusinessRuleException('Tanggal tercapai tidak boleh di masa depan.');
        }

        return DB::transaction(function () use ($milestone, $project, $date, $actor): ProjectMilestone {
            $milestone->forceFill(['actual_date' => $date, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.milestone_completed',
                description: 'Menandai milestone '.$milestone->name.' tercapai pada '.$date->translatedFormat('d M Y'),
                subject: $project,
                new: ['milestone' => $milestone->name, 'actual_date' => $date->toDateString()],
            );

            return $milestone;
        });
    }

    public function reopen(ProjectMilestone $milestone, User $actor): ProjectMilestone
    {
        $project = $milestone->project()->firstOrFail();
        $this->guard->ensureEditable($project);

        if ($milestone->actual_date === null) {
            return $milestone;
        }

        return DB::transaction(function () use ($milestone, $project, $actor): ProjectMilestone {
            $previous = $milestone->actual_date;
            $milestone->forceFill(['actual_date' => null, 'updated_by' => $actor->id])->save();

            $this->audit->record(
                event: 'project.milestone_reopened',
                description: 'Membuka kembali milestone '.$milestone->name,
                subject: $project,
                old: ['actual_date' => $previous->toDateString()],
            );

            return $milestone;
        });
    }

    private function owner(mixed $ownerId, ?int $currentOwnerId): ?int
    {
        if (blank($ownerId)) {
            return null;
        }

        $ownerId = (int) $ownerId;

        return $ownerId === $currentOwnerId ? $ownerId : $this->guard->activeUser($ownerId, 'Owner milestone')->id;
    }
}
