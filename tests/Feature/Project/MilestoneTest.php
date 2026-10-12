<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\ManageProjectMilestones;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\ProjectMilestone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class MilestoneTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    public function test_authorized_user_can_create_milestone_for_project(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->actingAs($manager);

        Livewire::test('projects.milestones', ['projectId' => $project->id])
            ->call('create')
            ->set('name', 'Design Completed')
            ->set('due_date', '2026-11-15')
            ->set('is_key_milestone', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('project-updated');

        $milestone = ProjectMilestone::query()->sole();
        $this->assertSame($project->id, $milestone->project_id);
        $this->assertSame('2026-11-15', $milestone->planned_date->toDateString());
        $this->assertTrue($milestone->planned_date->equalTo($milestone->current_due_date));
        $this->assertTrue(Activity::query()->where('event', 'project.milestone_created')->exists());
    }

    public function test_milestone_validation(): void
    {
        $project = $this->createProject();
        $this->actingAs($this->superAdmin());

        Livewire::test('projects.milestones', ['projectId' => $project->id])
            ->call('create')
            ->set('name', '')
            ->set('due_date', 'not-a-date')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'due_date' => 'date']);
    }

    public function test_user_without_project_access_cannot_manage_milestones(): void
    {
        $project = $this->createProject();
        $milestone = app(ManageProjectMilestones::class)->create($project, ['name' => 'Go Live', 'due_date' => '2026-12-01'], $this->superAdmin());

        $this->actingAs($this->staff());

        Livewire::test('projects.milestones', ['projectId' => $project->id])->assertForbidden();

        $this->assertNull($milestone->fresh()->actual_date);
    }

    public function test_due_date_change_requires_reason_and_is_tracked(): void
    {
        $project = $this->createProject();
        $actor = $this->superAdmin();
        $milestones = app(ManageProjectMilestones::class);
        $milestone = $milestones->create($project, ['name' => 'UAT', 'due_date' => '2026-11-20'], $actor);

        try {
            $milestones->update($milestone, ['name' => 'UAT', 'due_date' => '2026-11-27'], $actor);
            $this->fail('Reason is required when the due date changes.');
        } catch (BusinessRuleException) {
        }

        $milestones->update($milestone->fresh(), ['name' => 'UAT', 'due_date' => '2026-11-27', 'reason' => 'Client libur'], $actor);

        $milestone->refresh();
        $this->assertSame('2026-11-20', $milestone->planned_date->toDateString());
        $this->assertSame('2026-11-27', $milestone->current_due_date->toDateString());
        $this->assertSame('Client libur', $milestone->scheduleChanges()->sole()->reason);
    }

    public function test_completed_milestone_records_actual_date_and_can_be_reopened(): void
    {
        $project = $this->createProject();
        $actor = $this->superAdmin();
        $milestones = app(ManageProjectMilestones::class);
        $milestone = $milestones->create($project, ['name' => 'Requirement Approved', 'due_date' => today()->toDateString()], $actor);

        $milestones->complete($milestone, today()->toDateString(), $actor);
        $this->assertTrue($milestone->fresh()->isCompleted());

        $milestones->reopen($milestone->fresh(), $actor);
        $this->assertFalse($milestone->fresh()->isCompleted());

        $this->expectException(BusinessRuleException::class);
        $milestones->complete($milestone->fresh(), today()->addDay()->toDateString(), $actor);
    }

    public function test_milestone_of_another_project_cannot_be_targeted(): void
    {
        $project = $this->createProject();
        $other = $this->createProject(['name' => 'Other']);
        $foreign = app(ManageProjectMilestones::class)->create($other, ['name' => 'Foreign', 'due_date' => '2026-12-01'], $this->superAdmin());

        $this->actingAs($this->superAdmin());

        Livewire::test('projects.milestones', ['projectId' => $project->id])->call('edit', $foreign->id)->assertNotFound();
    }
}
