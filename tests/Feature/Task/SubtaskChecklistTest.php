<?php

namespace Tests\Feature\Task;

use App\Domain\Task\Actions\ManageChecklist;
use App\Domain\Task\Actions\ManageTaskSubtasks;
use App\Models\TaskSubtask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class SubtaskChecklistTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    public function test_pic_manages_subtasks_without_changing_task_progress(): void
    {
        $pic = $this->staff();
        $task = $this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]);

        $this->actingAs($pic);

        Livewire::test('tasks.subtasks', ['taskId' => $task->id])
            ->call('create')
            ->set('title', 'Database migration')
            ->call('save')
            ->assertHasNoErrors();

        $subtask = TaskSubtask::query()->sole();
        $this->assertSame($task->id, $subtask->task_id);

        Livewire::test('tasks.subtasks', ['taskId' => $task->id])->call('toggle', $subtask->id);

        $subtask->refresh();
        $this->assertTrue($subtask->is_completed);
        $this->assertSame($pic->id, $subtask->completed_by);
        $this->assertSame(0, $task->refresh()->progress_percentage, 'Subtasks do not drive task progress in Phase 3');
    }

    public function test_subtask_of_another_task_cannot_be_targeted(): void
    {
        $task = $this->createTask();
        $other = $this->createTask(['title' => 'Other']);
        $foreign = app(ManageTaskSubtasks::class)->create($other, ['title' => 'Foreign'], $this->superAdmin());

        $this->actingAs($this->superAdmin());

        Livewire::test('tasks.subtasks', ['taskId' => $task->id])->call('toggle', $foreign->id)->assertNotFound();
        $this->assertFalse($foreign->fresh()->is_completed);
    }

    public function test_outsider_cannot_manage_subtasks_or_checklist(): void
    {
        $task = $this->createTask(['visibility' => null]);

        $this->actingAs($this->staff());

        Livewire::test('tasks.subtasks', ['taskId' => $task->id])->assertForbidden();
        Livewire::test('tasks.checklist', ['ownerType' => 'task', 'ownerId' => $task->id])->assertForbidden();
    }

    public function test_viewer_without_participation_cannot_mutate_checklist(): void
    {
        $project = $this->createProject(['visibility' => 'company']);
        $task = $this->createTask(['project_id' => $project->id]);
        $item = app(ManageChecklist::class)->add($task, 'Endpoint documented');

        $this->actingAs($this->staff());

        Livewire::test('tasks.checklist', ['ownerType' => 'task', 'ownerId' => $task->id])
            ->assertSee('Endpoint documented')
            ->call('toggle', $item->id)
            ->assertForbidden();

        $this->assertFalse($item->fresh()->is_completed);
    }

    public function test_checklist_add_toggle_and_metadata(): void
    {
        $collaborator = $this->staff();
        $task = $this->createTask(['collaborator_ids' => [$collaborator->id]]);

        $this->actingAs($collaborator);

        Livewire::test('tasks.checklist', ['ownerType' => 'task', 'ownerId' => $task->id])
            ->set('newItem', '<b>Validation</b> done')
            ->call('add')
            ->assertHasNoErrors();

        $item = $task->checklistItems()->sole();
        $this->assertSame('Validation done', $item->text, 'Sanitized');

        Livewire::test('tasks.checklist', ['ownerType' => 'task', 'ownerId' => $task->id])->call('toggle', $item->id);

        $item->refresh();
        $this->assertTrue($item->is_completed);
        $this->assertSame($collaborator->id, $item->completed_by);
        $this->assertNotNull($item->completed_at);
    }

    public function test_subtask_checklist_is_supported(): void
    {
        $task = $this->createTask();
        $subtask = app(ManageTaskSubtasks::class)->create($task, ['title' => 'API'], $this->superAdmin());

        $item = app(ManageChecklist::class)->add($subtask, 'Unit test');

        $this->assertSame($subtask->id, $item->checklistable_id);
        $this->assertSame($task->id, app(ManageChecklist::class)->taskOf($item)->id);
    }
}
