<?php

namespace Tests\Feature\Task;

use App\Domain\System\Search\GlobalSearch;
use App\Enums\ProjectVisibility;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class TaskVisibilityTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    public function test_project_task_follows_project_visibility(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);
        $task = $this->createTask(['project_id' => $project->id]);

        $this->assertTrue($task->isVisibleTo($manager));
        $this->assertFalse($task->isVisibleTo($this->staff()));

        $project->forceFill(['visibility' => ProjectVisibility::Company])->save();
        $this->assertTrue($task->isVisibleTo($this->staff()));
    }

    public function test_standalone_task_visible_to_assignees_creator_and_department_members(): void
    {
        $department = Department::factory()->create();
        $creator = $this->staff();
        $pic = $this->staff();
        $collaborator = $this->staff();
        $departmentMember = $this->staff();
        DepartmentMembership::factory()->create(['user_id' => $departmentMember->id, 'department_id' => $department->id]);

        $task = $this->createTask(['department_id' => $department->id, 'pic_user_id' => $pic->id, 'collaborator_ids' => [$collaborator->id]], $creator);

        foreach ([$creator, $pic, $collaborator, $departmentMember] as $user) {
            $this->assertTrue($task->isVisibleTo($user));
        }

        $this->assertFalse($task->isVisibleTo($this->staff()));
        $this->assertTrue($task->isVisibleTo($this->superAdmin()));
    }

    public function test_staff_cannot_open_unrelated_task_by_changing_id(): void
    {
        $mine = $this->createTask(['department_id' => null, 'pic_user_id' => ($me = $this->staff())->id]);
        $other = $this->createTask(['title' => 'Private']);

        $this->actingAs($me)->get(route('tasks.show', $mine))->assertOk();
        $this->actingAs($me)->get(route('tasks.show', $other))->assertForbidden();
        $this->actingAs($me)->get(route('tasks.edit', $other))->assertForbidden();
    }

    public function test_inactive_user_sees_no_tasks(): void
    {
        $pic = $this->staff();
        $task = $this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]);
        $pic->forceFill(['is_active' => false])->save();

        $this->assertFalse($task->isVisibleTo($pic->fresh()));
        $this->assertSame(0, Task::query()->visibleTo($pic->fresh())->count());
    }

    public function test_global_search_never_returns_invisible_tasks(): void
    {
        $pic = $this->staff();
        $this->createTask(['title' => 'Alpha mine', 'department_id' => null, 'pic_user_id' => $pic->id]);
        $hidden = $this->createTask(['title' => 'Alpha hidden']);

        $group = collect(app(GlobalSearch::class)->search($pic, 'alpha'))->firstWhere('group', 'Tasks');

        $this->assertSame(['Alpha mine'], array_column($group['items'], 'title'));
        $this->assertNull(collect(app(GlobalSearch::class)->search($pic, $hidden->number))->firstWhere('group', 'Tasks'));
    }

    public function test_task_lists_and_dashboard_only_count_authorized_tasks(): void
    {
        $pic = $this->staff();
        $this->createTask(['title' => 'Visible task', 'department_id' => null, 'pic_user_id' => $pic->id, 'due_date' => today()->subDay()->toDateString()]);
        $this->createTask(['title' => 'Hidden task', 'due_date' => today()->subDay()->toDateString()]);

        $this->actingAs($pic);

        Livewire::test('tasks.table', ['mode' => 'all'])->set('quick', 'all')->assertSee('Visible task')->assertDontSee('Hidden task');
        Livewire::test('tasks.table', ['mode' => 'mine'])->assertSee('Visible task')->assertDontSee('Hidden task');

        $dashboard = Livewire::test('pages::dashboard')->instance();
        $cards = collect($dashboard->workCards)->keyBy('label');
        $this->assertSame(1, $cards['My Open Tasks']['value']);
        $this->assertSame(['Visible task'], collect($dashboard->attention)->where('label', 'Overdue Task')->pluck('title')->all());
    }

    public function test_project_tab_lists_only_tasks_of_that_project(): void
    {
        $project = $this->createProject();
        $this->createTask(['project_id' => $project->id, 'title' => 'Inside project']);
        $this->createTask(['title' => 'Outside project']);

        $this->actingAs($this->superAdmin());

        Livewire::test('tasks.table', ['mode' => 'project', 'projectId' => $project->id])
            ->assertSee('Inside project')
            ->assertDontSee('Outside project');
    }
}
