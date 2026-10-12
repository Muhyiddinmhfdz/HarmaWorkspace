<?php

namespace Tests\Feature\Task;

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Project\Actions\ManageProjectMilestones;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\TaskAssignmentRole;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskType;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class TaskCreationTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    public function test_project_manager_creates_project_task_from_project_context(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);
        $member = $this->staff();
        $this->addMember($project, $member);
        $department = $project->leadDepartment()->value('department_id');

        $this->actingAs($manager);

        Livewire::test('pages::tasks.create', ['lockedProjectId' => $project->id])
            ->assertSet('project_id', (string) $project->id)
            ->set('title', 'API Penjualan')
            ->set('department_id', (string) $department)
            ->set('pic_user_id', $member->id)
            ->set('due_date', '2026-11-20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $task = Task::query()->with(['picAssignment', 'status'])->sole();
        $this->assertMatchesRegularExpression('/^TSK-\d{4}-0001$/', $task->number);
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame($member->id, $task->picAssignment->user_id);
        $this->assertTrue($task->status->is_initial);
        $this->assertSame('2026-11-20', $task->planned_end->toDateString());
        $this->assertTrue($task->planned_end->equalTo($task->current_end));
        $this->assertNull($task->actual_start);
        $this->assertTrue(Activity::query()->where('event', 'task.created')->where('subject_id', $task->id)->exists());
    }

    public function test_task_numbers_use_their_own_sequence(): void
    {
        $project = $this->createProject();
        $first = $this->createTask();
        $second = $this->createTask(['title' => 'Second']);

        $this->assertStringEndsWith('0001', $first->number);
        $this->assertStringEndsWith('0002', $second->number);
        $this->assertStringStartsWith('PRJ-', $project->number);
        $this->assertStringStartsWith('TSK-', $first->number);
    }

    public function test_standalone_task_needs_department_or_pic(): void
    {
        $task = $this->createTask(['department_id' => null, 'pic_user_id' => $this->staff()->id]);
        $this->assertTrue($task->isStandalone());

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['department_id' => null, 'pic_user_id' => null]);
    }

    public function test_non_member_cannot_become_pic_of_project_task(): void
    {
        $project = $this->createProject();
        $outsider = $this->staff();

        try {
            $this->createTask(['project_id' => $project->id, 'pic_user_id' => $outsider->id]);
            $this->fail('Outsider must not be assignable.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('belum menjadi anggota Project ini', $e->getMessage());
        }

        $this->assertFalse($project->members()->where('user_id', $outsider->id)->exists(), 'No implicit membership');
    }

    public function test_department_must_be_involved_in_the_project(): void
    {
        $project = $this->createProject();

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['project_id' => $project->id, 'department_id' => Department::factory()->create()->id]);
    }

    public function test_milestone_must_belong_to_the_same_project(): void
    {
        $project = $this->createProject();
        $other = $this->createProject(['name' => 'Other']);
        $foreign = app(ManageProjectMilestones::class)->create($other, ['name' => 'Foreign', 'due_date' => '2026-12-01'], $this->superAdmin());

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['project_id' => $project->id, 'milestone_id' => $foreign->id]);
    }

    public function test_task_cannot_use_project_or_ticket_scoped_status_and_priority(): void
    {
        try {
            $this->createTask(['status_id' => $this->projectStatus('PLANNING')->id]);
            $this->fail('Project status must be rejected for tasks.');
        } catch (BusinessRuleException) {
        }

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['priority_id' => $this->projectPriority('HIGH')->id]);
    }

    public function test_new_task_cannot_start_terminal_or_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->createTask(['status_id' => $this->taskStatus('DONE')->id]);
    }

    public function test_inactive_task_type_is_rejected(): void
    {
        $type = TaskType::query()->create(['code' => 'OLD', 'name' => 'Old']);
        $type->forceFill(['is_active' => false])->save();

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['task_type_id' => $type->id]);
    }

    public function test_archived_or_finished_project_does_not_accept_tasks(): void
    {
        $manager = $this->staff();
        $project = $this->activateProject($this->createProject(['project_manager_id' => $manager->id, 'start_date' => today()->subWeek()->toDateString()]));
        app(CompleteProject::class)->handle($project, $this->superAdmin());

        try {
            $this->createTask(['project_id' => $project->id]);
            $this->fail('Completed project must not accept tasks.');
        } catch (BusinessRuleException) {
        }

        $this->assertFalse($manager->can('create', [Task::class, $project->refresh()]));

        app(ArchiveProject::class)->archive($project->refresh(), $this->superAdmin());

        $this->assertFalse($manager->can('create', [Task::class, $project->refresh()]));
        $this->actingAs($this->superAdmin())->get(route('tasks.create', ['project' => $project->id]))->assertForbidden();

        $this->expectException(BusinessRuleException::class);
        $this->createTask(['project_id' => $project->id]);
    }

    public function test_user_without_task_create_cannot_create_standalone_task(): void
    {
        $this->actingAs($this->userWithPermissions('task.view'))->get(route('tasks.create'))->assertForbidden();
    }

    public function test_project_member_with_task_create_may_add_tasks_but_outsider_may_not(): void
    {
        $project = $this->createProject(['visibility' => 'company']);
        $member = $this->staff();
        $this->addMember($project, $member);

        $this->assertTrue($member->can('create', [Task::class, $project]));
        $this->assertFalse($this->staff()->can('create', [Task::class, $project]));
    }

    public function test_collaborators_are_created_with_the_task(): void
    {
        $project = $this->createProject();
        [$a, $b] = [$this->staff(), $this->staff()];
        $this->addMember($project, $a);
        $this->addMember($project, $b);

        $task = $this->createTask(['project_id' => $project->id, 'pic_user_id' => $a->id, 'collaborator_ids' => [$a->id, $b->id]]);

        $this->assertSame([$b->id], $task->collaboratorAssignments()->pluck('user_id')->all(), 'PIC is not duplicated as collaborator');
        $this->assertSame(TaskAssignmentRole::Pic, $task->picAssignment()->firstOrFail()->role);
    }

    public function test_sensitive_task_attributes_are_not_mass_assignable(): void
    {
        $task = $this->createTask();

        $this->expectException(MassAssignmentException::class);
        $task->fill(['number' => 'X', 'status_id' => Status::query()->value('id'), 'progress_percentage' => 100]);
    }
}
