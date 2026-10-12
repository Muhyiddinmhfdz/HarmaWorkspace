<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\Actions\EnsureDefaultWorkflows;
use App\Enums\MasterScope;
use App\Enums\WorkflowVersionStatus;
use App\Models\Status;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowTransition;
use App\Models\WorkflowTransitionHistory;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class DefaultWorkflowTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    public function test_fresh_install_has_one_published_default_workflow_per_entity(): void
    {
        foreach (['project', 'task', 'ticket'] as $entity) {
            $workflow = $this->defaultWorkflow($entity);
            $version = $workflow->publishedVersion()->with('states.status')->sole();

            $this->assertTrue($workflow->is_active);
            $this->assertSame(WorkflowVersionStatus::Published, $version->status);
            $this->assertSame(1, $version->states->where('is_initial', true)->count());
            $this->assertSame(Status::query()->forScope(MasterScope::from($entity))->count(), $version->states->count(), 'Every status is a state');
            $this->assertTrue($version->states->every(fn ($state) => $state->status->scope->value === $entity));
        }
    }

    public function test_default_transitions_mirror_the_phase_2_to_4_lifecycles(): void
    {
        $paths = fn (string $entity) => WorkflowTransition::query()
            ->where('workflow_version_id', $this->defaultWorkflow($entity)->publishedVersion()->value('id'))
            ->with(['fromState', 'toState'])->get()
            ->map(fn ($transition) => $transition->fromState->code.'>'.$transition->toState->code.':'.$transition->operation)
            ->sort()->values()->all();

        $task = $paths('task');
        $this->assertContains('BACKLOG>TODO:move', $task);
        $this->assertContains('DONE>REVISION:move', $task);
        $this->assertContains('CANCELLED>TODO:move', $task);
        $this->assertNotContains('BACKLOG>DONE:move', $task);
        $this->assertCount(16, $task);

        $project = $paths('project');
        $this->assertContains('ACTIVE>COMPLETED:complete', $project);
        $this->assertNotContains('DRAFT>ACTIVE:move', $project);

        $ticket = $paths('ticket');
        $this->assertContains('WAITING_ASSIGNMENT>ASSIGNED:assign', $ticket);
        $this->assertContains('IN_REVIEW>RESOLVED:resolve', $ticket);
        $this->assertContains('NEED_INFO>IN_PROGRESS:provide_information', $ticket, 'Need Information resumes to where it came from');
        $this->assertContains('NEED_INFO>WAITING_ASSIGNMENT:provide_information', $ticket);
        $this->assertContains('CLOSED>REOPENED:reopen', $ticket);
    }

    public function test_new_records_are_bound_to_the_default_version_with_creation_history(): void
    {
        $task = $this->createTask();
        $ticket = $this->createTicket();
        $project = $this->createProject();

        $this->assertSame($this->defaultWorkflow('task')->publishedVersion()->value('id'), $task->workflow_version_id);
        $this->assertSame($this->defaultWorkflow('ticket')->publishedVersion()->value('id'), $ticket->workflow_version_id);
        $this->assertSame($this->defaultWorkflow('project')->publishedVersion()->value('id'), $project->workflow_version_id);
        $this->assertSame(3, WorkflowTransitionHistory::query()->whereNull('from_state_id')->count());
    }

    public function test_bootstrap_binds_existing_records_without_touching_statuses(): void
    {
        $task = $this->moveTask($this->createTask(), 'TODO', 'IN_PROGRESS');
        DB::table('tasks')->update(['workflow_version_id' => null]);

        app(EnsureDefaultWorkflows::class)->handle();

        $task->refresh();
        $this->assertSame($this->defaultWorkflow('task')->publishedVersion()->value('id'), $task->workflow_version_id);
        $this->assertSame('IN_PROGRESS', $task->status->code);
        $this->moveTask($task, 'IN_REVIEW');
    }

    public function test_system_seeder_never_replaces_or_duplicates_workflows(): void
    {
        $workflow = $this->defaultWorkflow('ticket');
        $workflow->forceFill(['name' => 'Proses Ticket HARMA'])->save();
        $counts = [Workflow::query()->count(), WorkflowTransition::query()->count()];

        $this->seed(SystemSeeder::class);
        $this->seed(SystemSeeder::class);

        $this->assertSame('Proses Ticket HARMA', $workflow->fresh()->name);
        $this->assertSame($counts, [Workflow::query()->count(), WorkflowTransition::query()->count()]);
    }

    public function test_records_created_by_factories_are_bound_lazily(): void
    {
        $task = Task::factory()->create();
        $this->assertNull($task->workflow_version_id);

        $this->moveTask($task, 'TODO');

        $this->assertNotNull($task->refresh()->workflow_version_id);
    }
}
