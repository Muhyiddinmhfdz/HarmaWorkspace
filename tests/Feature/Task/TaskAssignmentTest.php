<?php

namespace Tests\Feature\Task;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Actions\ManageTaskAssignments;
use App\Enums\TaskAssignmentRole;
use App\Models\Activity;
use App\Models\TaskAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class TaskAssignmentTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    public function test_database_allows_only_one_active_pic(): void
    {
        $task = $this->createTask(['pic_user_id' => $this->staff()->id]);

        $this->expectException(QueryException::class);
        TaskAssignment::query()->create(['task_id' => $task->id, 'user_id' => $this->staff()->id, 'role' => TaskAssignmentRole::Pic, 'assigned_at' => now()]);
    }

    public function test_pic_replacement_preserves_history(): void
    {
        $budi = $this->staff();
        $andi = $this->staff();
        $task = $this->createTask(['pic_user_id' => $budi->id]);

        app(ManageTaskAssignments::class)->setPic($task, $andi->id, $this->superAdmin());

        $this->assertSame($andi->id, $task->picAssignment()->value('user_id'));
        $old = $task->assignments()->where('user_id', $budi->id)->sole();
        $this->assertNotNull($old->ended_at);
        $this->assertNotNull($old->ended_by);
        $this->assertSame(1, $task->activeAssignments()->where('role', 'pic')->count());

        $log = Activity::query()->where('event', 'task.pic_changed')->sole();
        $this->assertSame($budi->name, $log->attribute_changes['old']['pic']);
        $this->assertSame($andi->name, $log->attribute_changes['attributes']['pic']);
    }

    public function test_multiple_collaborators_without_duplicates(): void
    {
        $task = $this->createTask();
        [$a, $b] = [$this->staff(), $this->staff()];
        $assignments = app(ManageTaskAssignments::class);

        $assignments->addCollaborator($task, $a->id, $this->superAdmin());
        $assignments->addCollaborator($task, $b->id, $this->superAdmin());
        $this->assertSame(2, $task->collaboratorAssignments()->count());

        $this->expectException(BusinessRuleException::class);
        $assignments->addCollaborator($task, $a->id, $this->superAdmin());
    }

    public function test_removing_collaborator_keeps_history_and_promotion_ends_collaboration(): void
    {
        $task = $this->createTask(['pic_user_id' => $this->staff()->id]);
        $rina = $this->staff();
        $assignments = app(ManageTaskAssignments::class);

        $collaboration = $assignments->addCollaborator($task, $rina->id, $this->superAdmin());
        $assignments->setPic($task, $rina->id, $this->superAdmin());

        $this->assertNotNull($collaboration->fresh()->ended_at, 'Collaborator row ends when promoted to PIC');
        $this->assertSame($rina->id, $task->picAssignment()->value('user_id'));

        $second = $assignments->addCollaborator($task, $this->staff()->id, $this->superAdmin());
        $assignments->removeCollaborator($task, $second->id, $this->superAdmin());

        $this->assertNotNull($second->fresh()->ended_at);
        $this->assertDatabaseHas('task_assignments', ['id' => $second->id]);
    }

    public function test_project_task_assignees_must_be_project_members(): void
    {
        $project = $this->createProject();
        $task = $this->createTask(['project_id' => $project->id]);

        $this->expectException(BusinessRuleException::class);
        app(ManageTaskAssignments::class)->addCollaborator($task, $this->staff()->id, $this->superAdmin());
    }

    public function test_unauthorized_user_cannot_assign(): void
    {
        $pic = $this->staff();
        $task = $this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]);

        $this->actingAs($pic);

        Livewire::test('pages::tasks.show', ['task' => $task])->call('openPic')->assertForbidden();
        Livewire::test('pages::tasks.show', ['task' => $task])->set('newPicId', $this->staff()->id)->call('savePic')->assertForbidden();
    }
}
