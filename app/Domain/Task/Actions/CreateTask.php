<?php

namespace App\Domain\Task\Actions;

use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Actions\RecalculateProjectProgress;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Numbering\NumberGenerator;
use App\Domain\System\Settings\SettingKey;
use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Enums\MasterScope;
use App\Enums\TaskAssignmentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Create a project task or a standalone task with its number, schedule
 * baseline (planned = current), PIC and collaborators.
 */
class CreateTask
{
    public function __construct(
        private NumberGenerator $numbers,
        private ScopedMasterGuard $masters,
        private ProjectGuard $projectGuard,
        private TaskGuard $guard,
        private TaskAudit $audit,
        private RecalculateProjectProgress $recalculate,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, project_id?: int|null, milestone_id?: int|null, department_id?: int|null, pic_user_id?: int|null, collaborator_ids?: list<int>, status_id?: int|null, priority_id: int, task_type_id?: int|null, task_category_id?: int|null, start_date?: string|null, due_date?: string|null, estimated_hours?: float|string|null, weight?: float|string|null}  $data
     */
    public function handle(array $data, User $actor): Task
    {
        $project = filled($data['project_id'] ?? null) ? Project::query()->with('status')->findOrFail((int) $data['project_id']) : null;

        if ($project !== null) {
            $this->projectGuard->ensureEditable($project);
            $this->guard->ensureProjectAcceptsWork($project);
        }

        $status = filled($data['status_id'] ?? null)
            ? $this->masters->status((int) $data['status_id'], MasterScope::Task)
            : $this->masters->initialStatus(MasterScope::Task);

        if ($status->is_terminal || $status->is_blocked) {
            throw new BusinessRuleException('Task baru tidak dapat langsung berstatus '.$status->name.'.');
        }

        $priority = $this->masters->priority((int) $data['priority_id'], MasterScope::Task);
        $department = $this->guard->department(filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null, $project);
        $milestone = $this->guard->milestone(filled($data['milestone_id'] ?? null) ? (int) $data['milestone_id'] : null, $project);
        $pic = filled($data['pic_user_id'] ?? null) ? $this->guard->assignee((int) $data['pic_user_id'], $project) : null;

        if ($department === null && $pic === null) {
            throw new BusinessRuleException('Task harus memiliki Department atau PIC agar jelas siapa yang bertanggung jawab.');
        }

        $collaborators = collect($data['collaborator_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === $pic?->id)
            ->map(fn (int $id): User => $this->guard->assignee($id, $project))
            ->values();

        $this->activeMaster(TaskType::class, $data['task_type_id'] ?? null, 'Task type');
        $this->activeMaster(TaskCategory::class, $data['task_category_id'] ?? null, 'Task category');

        [$start, $end] = $this->schedule($data['start_date'] ?? null, $data['due_date'] ?? null);

        return DB::transaction(function () use ($data, $actor, $project, $status, $priority, $department, $milestone, $pic, $collaborators, $start, $end): Task {
            $task = new Task([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'task_type_id' => filled($data['task_type_id'] ?? null) ? (int) $data['task_type_id'] : null,
                'task_category_id' => filled($data['task_category_id'] ?? null) ? (int) $data['task_category_id'] : null,
                'priority_id' => $priority->id,
            ]);

            $task->forceFill([
                'number' => $this->numbers->next('task', SettingKey::TaskNumberPrefix),
                'project_id' => $project?->id,
                'milestone_id' => $milestone?->id,
                'status_id' => $status->id,
                'department_id' => $department?->id,
                'planned_start' => $start,
                'planned_end' => $end,
                'current_start' => $start,
                'current_end' => $end,
                'actual_start' => $status->counts_as_started ? Date::today() : null,
                'estimated_hours' => filled($data['estimated_hours'] ?? null) ? $data['estimated_hours'] : null,
                'weight' => filled($data['weight'] ?? null) ? $data['weight'] : null,
                'progress_percentage' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $now = Date::now();

            if ($pic !== null) {
                TaskAssignment::query()->create(['task_id' => $task->id, 'user_id' => $pic->id, 'role' => TaskAssignmentRole::Pic, 'assigned_at' => $now, 'assigned_by' => $actor->id]);
            }

            foreach ($collaborators as $collaborator) {
                TaskAssignment::query()->create(['task_id' => $task->id, 'user_id' => $collaborator->id, 'role' => TaskAssignmentRole::Collaborator, 'assigned_at' => $now, 'assigned_by' => $actor->id]);
            }

            $this->audit->record($task, 'task.created', 'Membuat task '.$task->number.' '.$task->title, new: [
                'title' => $task->title,
                'status' => $status->name,
                'pic' => $pic?->name,
                'department' => $department?->name,
                'collaborators' => $collaborators->pluck('name')->all(),
                'due_date' => $end?->toDateString(),
            ]);

            $this->recalculate->handle($project?->id);

            return $task;
        });
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function schedule(?string $start, ?string $end): array
    {
        $startDate = filled($start) ? Carbon::parse($start)->startOfDay() : null;
        $endDate = filled($end) ? Carbon::parse($end)->startOfDay() : null;

        if ($startDate !== null && $endDate !== null && $endDate->lt($startDate)) {
            throw new BusinessRuleException('Deadline tidak boleh lebih awal dari tanggal mulai.');
        }

        return [$startDate, $endDate];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function activeMaster(string $model, mixed $id, string $label): void
    {
        if (filled($id) && ! $model::query()->whereKey((int) $id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException($label.' nonaktif tidak dapat dipilih.');
        }
    }
}
