<?php

namespace App\Domain\Project\Support;

use App\Domain\Task\Support\TaskWeight;
use App\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Task-weighted progress (ADR-044), computed by the database:
 *
 *   Σ(effective weight × task progress) / Σ(effective weight)
 *
 * Cancelled tasks (terminal but not completed) are excluded from both sums;
 * no active task means 0%. Department progress uses the same formula filtered
 * by the task department.
 */
class ProjectProgressCalculator
{
    /**
     * @return array{progress: int, tasks: int}
     */
    public function overall(Project $project): array
    {
        $row = $this->counted($project->id)
            ->selectRaw('COUNT(*) as tasks, SUM('.TaskWeight::SQL.' * tasks.progress_percentage) as weighted, SUM('.TaskWeight::SQL.') as total')
            ->first();

        return [
            'progress' => $this->percentage((float) ($row->weighted ?? 0), (float) ($row->total ?? 0)),
            'tasks' => (int) ($row->tasks ?? 0),
        ];
    }

    /**
     * Progress per involved department (departments without tasks show 0%).
     *
     * @return Collection<int, array{department_id: int, name: string, is_lead: bool, progress: int, tasks: int}>
     */
    public function byDepartment(Project $project): Collection
    {
        $rows = $this->counted($project->id)
            ->whereNotNull('tasks.department_id')
            ->groupBy('tasks.department_id')
            ->selectRaw('tasks.department_id, COUNT(*) as tasks, SUM('.TaskWeight::SQL.' * tasks.progress_percentage) as weighted, SUM('.TaskWeight::SQL.') as total')
            ->get()
            ->keyBy('department_id');

        return $project->departments()->with('department:id,name')->orderByDesc('is_lead')->get()
            ->map(function ($projectDepartment) use ($rows): array {
                $row = $rows->get($projectDepartment->department_id);

                return [
                    'department_id' => $projectDepartment->department_id,
                    'name' => $projectDepartment->department->name,
                    'is_lead' => $projectDepartment->is_lead,
                    'progress' => $row ? $this->percentage((float) $row->weighted, (float) $row->total) : 0,
                    'tasks' => $row ? (int) $row->tasks : 0,
                ];
            });
    }

    /**
     * Tasks of the project that count towards progress (not cancelled).
     */
    private function counted(int $projectId): Builder
    {
        return DB::table('tasks')
            ->join('statuses', 'statuses.id', '=', 'tasks.status_id')
            ->where('tasks.project_id', $projectId)
            ->where(fn (Builder $query) => $query->where('statuses.is_terminal', false)->orWhere('statuses.counts_as_completed', true));
    }

    private function percentage(float $weighted, float $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round($weighted / $total)));
    }
}
