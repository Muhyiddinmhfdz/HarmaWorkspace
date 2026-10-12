<?php

namespace Tests\Feature\Task;

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\ChangeProjectProgressMode;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Domain\Project\Support\ProjectProgressCalculator;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Actions\ChangeTaskEstimate;
use App\Domain\Task\Actions\UpdateTaskProgress;
use App\Domain\Task\Support\TaskWeight;
use App\Enums\ProgressMode;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class ProjectProgressTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    private function weightedProject(array $overrides = []): Project
    {
        $project = $this->createProject($overrides);

        return app(ChangeProjectProgressMode::class)->handle($project, ProgressMode::TaskWeighted, $this->superAdmin());
    }

    private function taskWith(Project $project, float $weight, int $progress, ?int $departmentId = null): Task
    {
        $task = $this->createTask(['project_id' => $project->id, 'weight' => $weight, 'department_id' => $departmentId ?? $project->leadDepartment()->value('department_id')]);

        if ($progress > 0) {
            $task = $this->moveTask($task, 'TODO', 'IN_PROGRESS');
            app(UpdateTaskProgress::class)->handle($task, $progress, $this->superAdmin());
        }

        return $task->refresh();
    }

    public function test_weighted_formula(): void
    {
        $project = $this->weightedProject();
        $this->taskWith($project, 40, 50);
        $this->taskWith($project, 30, 60);
        $this->taskWith($project, 10, 100);
        $this->taskWith($project, 20, 0);

        // (40×50 + 30×60 + 10×100 + 20×0) / 100 = 48
        $this->assertSame(48, $project->refresh()->progress_percentage);
        $this->assertSame(['progress' => 48, 'tasks' => 4], app(ProjectProgressCalculator::class)->overall($project));
    }

    public function test_cancelled_tasks_are_excluded(): void
    {
        $project = $this->weightedProject();
        $this->taskWith($project, 50, 100);
        $this->taskWith($project, 30, 50);
        $cancelled = $this->taskWith($project, 20, 0);

        $this->moveTask($cancelled, 'CANCELLED');

        // (50×100 + 30×50) / 80 = 81.25 → 81
        $this->assertSame(81, $project->refresh()->progress_percentage);
    }

    public function test_project_without_tasks_is_zero_percent(): void
    {
        $project = $this->weightedProject();

        $this->assertSame(0, $project->progress_percentage);
        $this->assertSame(['progress' => 0, 'tasks' => 0], app(ProjectProgressCalculator::class)->overall($project));
    }

    public function test_effective_weight_resolution(): void
    {
        $manual = new Task;
        $manual->forceFill(['weight' => '5', 'estimated_hours' => '8']);
        $estimate = new Task;
        $estimate->forceFill(['weight' => null, 'estimated_hours' => '8']);
        $fallback = new Task;
        $fallback->forceFill(['weight' => null, 'estimated_hours' => '0']);

        $this->assertSame(5.0, TaskWeight::of($manual));
        $this->assertSame(8.0, TaskWeight::of($estimate));
        $this->assertSame(1.0, TaskWeight::of($fallback));
    }

    public function test_weight_and_estimate_changes_recalculate(): void
    {
        $project = $this->weightedProject();
        $done = $this->taskWith($project, 1, 100);
        $this->taskWith($project, 1, 0);
        $this->assertSame(50, $project->refresh()->progress_percentage);

        app(ChangeTaskEstimate::class)->handle($done, 8, null, $this->superAdmin());
        $this->assertSame(89, $project->refresh()->progress_percentage); // 8×100 / 9

        app(ChangeTaskEstimate::class)->handle($done->refresh(), 8, 1.0, $this->superAdmin());
        $this->assertSame(50, $project->refresh()->progress_percentage);
    }

    public function test_manual_progress_is_read_only_in_task_weighted_mode(): void
    {
        $manager = $this->staff();
        $project = $this->weightedProject(['project_manager_id' => $manager->id]);

        try {
            app(UpdateProjectProgress::class)->handle($project, 70, $this->superAdmin());
            $this->fail('Manual progress must be rejected in task-weighted mode.');
        } catch (BusinessRuleException) {
        }

        $this->actingAs($manager);
        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('progress', 70)
            ->call('saveProgress')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame(0, $project->refresh()->progress_percentage);
    }

    public function test_switching_back_to_manual_keeps_last_value(): void
    {
        $project = $this->weightedProject();
        $this->taskWith($project, 1, 60);
        $this->assertSame(60, $project->refresh()->progress_percentage);

        app(ChangeProjectProgressMode::class)->handle($project, ProgressMode::Manual, $this->superAdmin());
        $this->assertSame(60, $project->refresh()->progress_percentage);

        app(UpdateProjectProgress::class)->handle($project, 70, $this->superAdmin());
        $this->assertSame(70, $project->refresh()->progress_percentage);
    }

    public function test_existing_projects_stay_manual_and_ignore_task_changes(): void
    {
        $project = $this->createProject();
        $this->taskWith($project, 1, 100);

        $this->assertSame(ProgressMode::Manual, $project->refresh()->progress_mode);
        $this->assertSame(0, $project->progress_percentage);
    }

    public function test_department_progress(): void
    {
        $programmer = Department::factory()->create(['name' => 'Programmer']);
        $qa = Department::factory()->create(['name' => 'QA']);
        $project = $this->weightedProject(['lead_department_id' => $programmer->id, 'supporting_department_ids' => [$qa->id]]);

        $this->taskWith($project, 3, 100, $programmer->id);
        $this->taskWith($project, 1, 0, $programmer->id);
        $this->taskWith($project, 1, 20, $qa->id);

        $rows = app(ProjectProgressCalculator::class)->byDepartment($project)->keyBy('name');

        $this->assertSame(75, $rows['Programmer']['progress']);
        $this->assertSame(2, $rows['Programmer']['tasks']);
        $this->assertSame(20, $rows['QA']['progress']);
        $this->assertSame(64, $project->refresh()->progress_percentage); // (300 + 0 + 20) / 5
    }

    public function test_project_with_active_tasks_cannot_be_completed(): void
    {
        $project = $this->activateProject($this->createProject(['start_date' => today()->subMonth()->toDateString()]));
        $open = $this->moveTask($this->createTask(['project_id' => $project->id]), 'TODO', 'IN_PROGRESS');
        $blocked = $this->moveTask($this->createTask(['project_id' => $project->id]), 'TODO', 'IN_PROGRESS', 'BLOCKED');

        try {
            app(CompleteProject::class)->handle($project, $this->superAdmin());
            $this->fail('Project with active tasks must not complete.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('masih terdapat 2 Task aktif', $e->getMessage());
            $this->assertStringContainsString('1 Task masih In Progress', $e->getMessage());
            $this->assertStringContainsString('1 Task masih Blocked', $e->getMessage());
        }

        $this->moveTask($open, 'IN_REVIEW', 'DONE');
        $this->moveTask($blocked, 'CANCELLED');

        app(CompleteProject::class)->handle($project->refresh(), $this->superAdmin());
        $this->assertTrue($project->refresh()->status->counts_as_completed);
    }

    public function test_tasks_of_archived_project_are_read_only(): void
    {
        $project = $this->activateProject($this->createProject(['start_date' => today()->subMonth()->toDateString()]));
        $task = $this->moveTask($this->createTask(['project_id' => $project->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE');
        app(CompleteProject::class)->handle($project->refresh(), $this->superAdmin());
        app(ArchiveProject::class)->archive($project->refresh(), $this->superAdmin());

        $this->actingAs($this->superAdmin())->get(route('tasks.show', $task))->assertOk()->assertDontSee('Update progress');

        $this->expectException(BusinessRuleException::class);
        $this->moveTask($task, 'REVISION');
    }
}
