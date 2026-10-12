<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Support\TransitionContext;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Complete a project: completion status, actual end date, 100% manual progress
 * and the completion health. Every task must be finished (completed or
 * cancelled) first (ADR-047), and every related ticket resolved, closed,
 * rejected or cancelled (ADR-059). Nothing is closed automatically.
 */
class CompleteProject
{
    public function __construct(
        private AuditLogger $audit,
        private ProjectGuard $guard,
        private WorkflowGate $workflow,
    ) {}

    public function handle(Project $project, User $actor, ?string $actualEnd = null, ?TransitionContext $context = null): Project
    {
        $this->guard->ensureEditable($project);

        $status = $context?->transition !== null
            ? $context->transition->toState()->with('status')->firstOrFail()->status
            : Status::query()->forScope(MasterScope::Project)->active()->where('counts_as_completed', true)->orderBy('sort_order')->first()
                ?? throw new BusinessRuleException('Belum ada status yang menandai project selesai. Atur di Master Data.');

        $end = $actualEnd !== null ? Carbon::parse($actualEnd)->startOfDay() : Date::today();

        return DB::transaction(function () use ($project, $actor, $status, $end, $context): Project {
            $project = Project::query()->lockForUpdate()->with('status')->findOrFail($project->id);

            if ($project->status->counts_as_completed) {
                throw new BusinessRuleException('Project sudah berstatus selesai.');
            }

            $transition = $this->workflow->resolve($project, $project->status, $status, $context, 'complete');
            $this->ensureNoActiveWork($project);

            $actualStart = $project->actual_start ?? $project->current_start;

            if ($end->lt($actualStart)) {
                throw new BusinessRuleException('Tanggal selesai tidak boleh sebelum tanggal mulai aktual ('.$actualStart->format('d M Y').').');
            }

            $health = ProjectHealthStatus::query()->active()->where('represents_completion', true)->first();
            $previous = $project->status;
            $previousStatus = $previous->name;

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
            $this->workflow->record($project, $transition, $previous, $status, $actor, null, array_filter(['approval_request_id' => $context?->approvalRequestId]));

            return $project;
        });
    }

    /**
     * Blocks completion while tasks or tickets are still active, with a
     * per-status summary so the project manager knows what to follow up.
     */
    public function ensureNoActiveWork(Project $project): void
    {
        $summary = fn ($query, string $table) => $query
            ->where($table.'.project_id', $project->id)
            ->join('statuses', 'statuses.id', '=', $table.'.status_id')
            ->selectRaw('statuses.name, COUNT(*) as aggregate')
            ->groupBy('statuses.id', 'statuses.name', 'statuses.sort_order')
            ->orderBy('statuses.sort_order')
            ->pluck('aggregate', 'name');

        $tasks = $summary(Task::query()->open(), 'tasks');
        $tickets = $summary(Ticket::query()->open(), 'tickets');

        if ($tasks->isEmpty() && $tickets->isEmpty()) {
            return;
        }

        $parts = [];

        if ($tasks->isNotEmpty()) {
            $parts[] = $tasks->sum().' Task aktif: '.$tasks->map(fn ($count, string $name): string => $count.' Task masih '.$name)->implode(', ');
        }

        if ($tickets->isNotEmpty()) {
            $parts[] = $tickets->sum().' Ticket aktif: '.$tickets->map(fn ($count, string $name): string => $count.' Ticket masih '.$name)->implode(', ');
        }

        throw new BusinessRuleException('Project belum dapat diselesaikan karena masih terdapat '.implode('; serta ', $parts).'.');
    }
}
