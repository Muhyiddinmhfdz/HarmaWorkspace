<?php

namespace Tests\Feature\Task;

use App\Models\Activity;
use App\Models\ApprovalRequest;
use App\Models\Department;
use App\Models\Task;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class TaskOperationalUxTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    public function test_quick_create_from_project_needs_only_a_title(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        $this->addMember($project, $member);
        $this->actingAs($member);

        Livewire::withQueryParams(['project' => $project->id])
            ->test('pages::tasks.create')
            ->assertSet('project_id', (string) $project->id)
            ->assertSet('pic_user_id', $member->id)
            ->assertSet('department_id', (string) $project->leadDepartment()->value('department_id'))
            ->set('title', 'API Payment')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::query()->where('title', 'API Payment')->firstOrFail();
        $this->assertSame($project->id, $task->project_id);
        $this->assertTrue($task->status->is_initial, 'Status defaults to the initial status');
        $this->assertSame($member->id, $task->picAssignment->user_id);
    }

    public function test_quick_create_keeps_backend_validation_and_locked_project(): void
    {
        $project = $this->createProject();
        $this->actingAs($this->superAdmin());

        Livewire::withQueryParams(['project' => $project->id])
            ->test('pages::tasks.create')
            ->set('title', '')
            ->set('department_id', '')
            ->set('pic_user_id', null)
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'department_id', 'pic_user_id']);

        Livewire::withQueryParams(['project' => $project->id])
            ->test('pages::tasks.create')
            ->set('project_id', (string) $this->createProject(['name' => 'Other'])->id)
            ->assertForbidden();
    }

    public function test_quick_progress_update_goes_through_the_domain_action(): void
    {
        $project = $this->createProject();
        $task = $this->moveTask($this->createTask(['project_id' => $project->id]), 'TODO', 'IN_PROGRESS');
        $this->actingAs($this->superAdmin());

        Livewire::test('tasks.table', ['mode' => 'project', 'projectId' => $project->id])
            ->call('openProgress', $task->id)
            ->set('progress', 75)
            ->call('saveProgress')
            ->assertDispatched('notify', type: 'success', message: 'Progress Task '.$task->number.' diperbarui menjadi 75%.');

        $this->assertSame(75, $task->refresh()->progress_percentage);
        $this->assertTrue(Activity::query()->where('subject_type', 'task')->where('subject_id', $task->id)->where('event', 'task.progress_changed')->exists());
    }

    public function test_my_tasks_sections_focus_on_attention_first(): void
    {
        $pic = $this->staff();
        $department = Department::factory()->create();
        $assign = fn (string $title, string ...$codes): Task => $this->moveTask($this->createTask(['title' => $title, 'department_id' => $department->id, 'pic_user_id' => $pic->id]), ...$codes);

        $assign('Blocked one', 'TODO', 'IN_PROGRESS', 'BLOCKED');
        $late = $assign('Late one', 'TODO');
        $late->forceFill(['current_end' => today()->subDay()])->save();
        $assign('Working one', 'TODO', 'IN_PROGRESS');
        $assign('Next one', 'TODO');
        $assign('Finished one', 'TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE');

        $this->actingAs($pic);
        $table = Livewire::test('tasks.table', ['mode' => 'mine']);

        $table->assertSet('quick', 'attention')
            ->assertSee('Blocked one')->assertSee('Late one')
            ->assertDontSee('Working one')->assertDontSee('Finished one');
        $table->set('quick', 'in_progress')->assertSee('Working one')->assertDontSee('Next one');
        $table->set('quick', 'upcoming')->assertSee('Next one')->assertDontSee('Working one');
        $table->set('quick', 'completed')->assertSee('Finished one');

        $this->assertSame(['Needs Attention', 'In Progress', 'Blocked', 'Upcoming', 'Completed'], array_column($table->instance()->quickViews, 'label'));
    }

    public function test_task_detail_primary_action_runs_the_workflow(): void
    {
        $task = $this->moveTask($this->createTask(['project_id' => $this->createProject()->id]), 'TODO');
        $this->actingAs($this->superAdmin());

        $page = Livewire::test('pages::tasks.show', ['task' => $task]);
        $primary = $page->instance()->primaryTransition;
        $this->assertNotNull($primary);

        $page->call('startTransition', $primary['id']);

        $this->assertSame('IN_PROGRESS', $task->refresh()->status->code);
        $this->assertTrue(WorkflowTransitionHistory::query()->where('subject_type', 'task')->where('subject_id', $task->id)->where('workflow_transition_id', $primary['id'])->exists());
    }

    public function test_approval_gated_primary_action_still_requires_approval(): void
    {
        $template = $this->approvalTemplate([['approver_type' => 'department_leader']], 'task', 'Task Review');
        $draft = $this->draftCopy('task', 'Task with Review Approval');
        $this->editTransition($draft, $this->transitionIn($draft, 'IN_REVIEW', 'DONE'), ['approval_template_id' => $template->id]);
        $this->publish($draft, makeDefault: true);

        $department = Department::factory()->create();
        $this->member($department, leader: true);
        $pm = $this->staff();
        $project = $this->createProject(['project_manager_id' => $pm->id, 'lead_department_id' => $department->id]);
        $task = $this->moveTask($this->createTask(['project_id' => $project->id, 'department_id' => $department->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW');

        $this->actingAs($pm);
        $page = Livewire::test('pages::tasks.show', ['task' => $task]);
        $primary = $page->instance()->primaryTransition;
        $this->assertSame('approval', $primary['kind'], 'The forward step is offered, flagged as needing approval');

        $page->call('startTransition', $primary['id'])
            ->set('transitionInput.comment', 'Siap direview.')
            ->call('submitTransition');

        $this->assertSame('IN_REVIEW', $task->refresh()->status->code, 'Status does not change before approval');
        $this->assertSame(1, ApprovalRequest::query()->count());
    }

    public function test_task_detail_hides_technical_fields_behind_more_details(): void
    {
        $task = $this->createTask(['project_id' => $this->createProject()->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSeeInOrder(['PIC', 'Department', 'Priority', 'Project', 'Deadline', 'More Details', 'Bobot progress'])
            ->assertSee('Related Reports')
            ->assertDontSee('Workflow Version')
            ->assertDontSee('Effective Weight');
    }
}
