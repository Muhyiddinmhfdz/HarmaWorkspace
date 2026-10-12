<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\ManageProjectDepartments;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Department;
use App\Models\ProjectDepartment;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectTeamTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    public function test_supporting_department_can_be_added_and_removed_with_history(): void
    {
        $project = $this->createProject();
        $qa = Department::factory()->create(['name' => 'QA']);
        $departments = app(ManageProjectDepartments::class);

        $record = $departments->add($project, $qa->id, 'Testing', null);
        $this->assertFalse($record->is_lead);

        $departments->remove($record);

        $this->assertNotNull($record->fresh()->removed_at);
        $this->assertSame(1, $project->departments()->count());
        $this->assertTrue(Activity::query()->where('event', 'project.department_removed')->exists());
    }

    public function test_duplicate_department_cannot_be_attached_twice(): void
    {
        $project = $this->createProject();
        $qa = Department::factory()->create();
        app(ManageProjectDepartments::class)->add($project, $qa->id, null, null);

        $this->expectException(BusinessRuleException::class);
        app(ManageProjectDepartments::class)->add($project, $qa->id, null, null);
    }

    public function test_database_enforces_single_active_lead_department(): void
    {
        $project = $this->createProject();
        $extra = new ProjectDepartment(['project_id' => $project->id, 'department_id' => Department::factory()->create()->id]);

        $this->expectException(QueryException::class);
        $extra->forceFill(['is_lead' => true])->save();
    }

    public function test_lead_department_can_be_changed_but_not_removed(): void
    {
        $original = Department::factory()->create();
        $project = $this->createProject(['lead_department_id' => $original->id]);
        $newLead = Department::factory()->create();
        $departments = app(ManageProjectDepartments::class);

        $departments->changeLead($project, $newLead->id);

        $this->assertSame($newLead->id, $project->leadDepartment()->value('department_id'));
        $this->assertSame(1, $project->departments()->where('is_lead', true)->count());
        $this->assertTrue($project->departments()->where('department_id', $original->id)->exists(), 'Previous lead stays as supporting');

        $this->expectException(BusinessRuleException::class);
        $departments->remove($project->leadDepartment()->firstOrFail());
    }

    public function test_project_can_have_multiple_members_with_contextual_roles(): void
    {
        $project = $this->createProject();
        [$andi, $rina] = User::factory()->count(2)->withRole('Staff')->create();
        $members = app(ManageProjectMembers::class);

        $members->add($project, $andi->id, $this->projectRole('DEVELOPER')->id, null);
        $members->add($project, $rina->id, $this->projectRole('REVIEWER')->id, null);

        $this->assertSame(['Developer', 'Reviewer'], $project->members()->with('role')->get()->pluck('role.name')->sort()->values()->all());
        $this->assertTrue($andi->hasRole('Staff'), 'System role is untouched by project roles');
    }

    public function test_same_user_has_different_roles_in_different_projects(): void
    {
        $andi = $this->staff();
        $pos = $this->createProject(['project_manager_id' => $andi->id]);
        $hris = $this->createProject(['name' => 'HRIS']);
        app(ManageProjectMembers::class)->add($hris, $andi->id, $this->projectRole('MEMBER')->id, null);

        $this->assertTrue($pos->isManagedBy($andi));
        $this->assertFalse($hris->fresh()->isManagedBy($andi));
        $this->assertSame('Member', $hris->members()->where('user_id', $andi->id)->firstOrFail()->role->name);
    }

    public function test_active_membership_is_unique_and_history_is_kept(): void
    {
        $project = $this->createProject();
        $user = $this->staff();
        $members = app(ManageProjectMembers::class);

        $membership = $members->add($project, $user->id, $this->projectRole()->id, null);

        try {
            $members->add($project, $user->id, $this->projectRole()->id, null);
            $this->fail('Duplicate active membership must be rejected.');
        } catch (BusinessRuleException) {
        }

        $members->changeRole($membership, $this->projectRole('REVIEWER')->id);
        $members->remove($membership);
        $members->add($project, $user->id, $this->projectRole()->id, null);

        $this->assertSame(2, ProjectMember::query()->where('project_id', $project->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, $project->members()->count());
        $this->assertTrue(Activity::query()->where('event', 'project.member_role_changed')->exists());
    }

    public function test_project_manager_manages_team_from_the_ui(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);
        $newcomer = $this->staff();
        $qa = Department::factory()->create();

        $this->actingAs($manager);

        Livewire::test('projects.team', ['projectId' => $project->id])
            ->call('addMember')
            ->set('user_id', $newcomer->id)
            ->set('project_role_id', (string) $this->projectRole('DEVELOPER')->id)
            ->call('saveMember')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success')
            ->call('addDepartment')
            ->set('department_id', (string) $qa->id)
            ->set('function', 'Testing')
            ->call('saveDepartment')
            ->assertHasNoErrors();

        $this->assertTrue($project->members()->where('user_id', $newcomer->id)->exists());
        $this->assertTrue($project->departments()->where('department_id', $qa->id)->exists());
    }

    public function test_inactive_user_cannot_be_added_as_member(): void
    {
        $project = $this->createProject();

        $this->expectException(BusinessRuleException::class);
        app(ManageProjectMembers::class)->add($project, User::factory()->inactive()->create()->id, $this->projectRole()->id, null);
    }
}
