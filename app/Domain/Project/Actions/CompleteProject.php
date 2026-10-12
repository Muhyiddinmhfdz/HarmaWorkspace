<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Project\Support\ProjectLifecycle;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Complete a project: completion status, actual end date, 100% manual progress
 * and the completion health. Every task must be finished (completed or
 * cancelled) first (ADR-047).
 */
class CompleteProject
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
        private ProjectLifecycle $lifecycle,
    ) {}

    public function handle(Project $project, User $actor, ?string $actualEnd = null): Project
    {
        $this->guard->ensureEditable($project);

        $status = Status::query()->forScope(MasterScope::Project)->active()->where('counts_as_completed', true)->orderBy('sort_order')->first()
            ?? throw new BusinessRuleException('Belum ada status yang menandai project selesai. Atur di Master Data.');

        $end = $actualEnd !== null ? Carbon::parse($actualEnd)->startOfDay() : Date::today();

        return DB::transaction(function () use ($project, $actor, $status, $end): Project {
            $project = Project::query()->lockForUpdate()->with('status')->findOrFail($project->id);

            if ($project->status->counts_as_completed) {
                throw new BusinessRuleException('Project sudah berstatus selesai.');
            }

            if (! $this->lifecycle->allows($project->status, $status)) {
                throw new BusinessRuleException($this->lifecycle->rejectionMessage($project->status, $status));
            }

            $this->ensureNoActiveTasks($project);

            $actualStart = $project->actual_start ?? $project->current_start;

            if ($end->lt($actualStart)) {
                throw new BusinessRuleException('Tanggal selesai tidak boleh sebelum tanggal mulai aktual ('.$actualStart->format('d M Y').').');
            }

            $health = ProjectHealthStatus::query()->active()->where('represents_completion', true)->first();
            $previousStatus = $project->status->name;

            $project->forceFill([
                'status_id' => $status->id,
                'actual_start' => $actualStart,
                'actual_end' => $end,
                'completed_at' => Date::now(),
                'progress_percentage' => $project->progress_mode === ProgressMode::Manual ? 100 : $project->progress_percentage,
                'health_status_id' => $health->id ?? $project->health_status_id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                event: 'project.completed',
                description: 'Menyelesaikan project '.$project->name,
                subject: $project,
                old: ['status' => $previousStatus],
                new: ['status' => $status->name, 'actual_end' => $end->toDateString()],
            );

            return $project;
        });
    }

    /**
     * Blocks completion while tasks are still open, with a per-status summary.
     */
    private function ensureNoActiveTasks(Project $project): void
    {
        $open = Task::query()
            ->where('project_id', $project->id)
            ->open()
            ->join('statuses', 'statuses.id', '=', 'tasks.status_id')
            ->selectRaw('statuses.name, COUNT(*) as aggregate')
            ->groupBy('statuses.id', 'statuses.name', 'statuses.sort_order')
            ->orderBy('statuses.sort_order')
            ->pluck('aggregate', 'name');

        if ($open->isEmpty()) {
            return;
        }

        $details = $open->map(fn ($count, string $name): string => $count.' Task masih '.$name)->implode(', ');

        throw new BusinessRuleException('Project belum dapat diselesaikan karena masih terdapat '.$open->sum().' Task aktif: '.$details.'.');
    }
}
