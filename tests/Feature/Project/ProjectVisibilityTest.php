<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\ChangeProjectVisibility;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Search\GlobalSearch;
use App\Enums\ProjectVisibility;
use App\Models\Activity;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectVisibilityTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    /**
     * @return list<int>
     */
    private function visibleIds(User $user): array
    {
        return Project::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
    }

    private function memberOf(Department $department, bool $leader = false): User
    {
        $user = $this->staff();
        DepartmentMembership::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'is_primary' => true, 'is_leader' => $leader]);

        return $user;
    }

    public function test_new_project_defaults_to_restricted_even_without_client(): void
    {
        $project = $this->createProject(['client_id' => null]);

        $this->assertSame(ProjectVisibility::Restricted, $project->visibility);
        $this->assertFalse($project->isVisibleTo($this->staff()), 'Internal project (no client) is not company-wide');
    }

    public function test_restricted_project_visible_to_team_and_department_leaders_only(): void
    {
        $manager = $this->staff();
        $owner = $this->staff();
        $member = $this->staff();
        $leadDepartment = Department::factory()->create();
        $leader = $this->memberOf($leadDepartment, leader: true);
        $departmentStaff = $this->memberOf($leadDepartment);
        $outsider = $this->staff();

        $project = $this->createProject(['project_manager_id' => $manager->id, 'owner_id' => $owner->id, 'lead_department_id' => $leadDepartment->id]);
        app(ManageProjectMembers::class)->add($project, $member->id, $this->projectRole()->id, null);
        $other = $this->createProject(['name' => 'Other']);

        foreach (['PM' => $manager, 'owner' => $owner, 'member' => $member, 'department leader' => $leader] as $label => $user) {
            $this->assertSame([$project->id], $this->visibleIds($user), $label.' should see only their project');
        }

        $this->assertSame([], $this->visibleIds($departmentStaff), 'Plain department member does not see a restricted project');
        $this->assertSame([], $this->visibleIds($outsider));
        $this->assertFalse($other->isVisibleTo($manager));
    }

    public function test_department_project_visible_to_members_of_involved_departments(): void
    {
        $lead = Department::factory()->create();
        $supporting = Department::factory()->create();
        $unrelated = Department::factory()->create();
        $project = $this->createProject([
            'lead_department_id' => $lead->id,
            'supporting_department_ids' => [$supporting->id],
            'visibility' => ProjectVisibility::Department->value,
        ]);

        $this->assertTrue($project->isVisibleTo($this->memberOf($lead)));
        $this->assertTrue($project->isVisibleTo($this->memberOf($supporting)));
        $this->assertFalse($project->isVisibleTo($this->memberOf($unrelated)));
    }

    public function test_department_visibility_ignores_ended_memberships_and_removed_departments(): void
    {
        $lead = Department::factory()->create();
        $project = $this->createProject(['lead_department_id' => $lead->id, 'visibility' => ProjectVisibility::Department->value]);
        $former = $this->memberOf($lead);
        $former->memberships()->update(['ended_at' => today(), 'is_primary' => false]);

        $this->assertFalse($project->isVisibleTo($former->fresh()));
    }

    public function test_company_project_visible_to_eligible_active_users_only(): void
    {
        $project = $this->createProject(['visibility' => ProjectVisibility::Company->value]);

        $this->assertTrue($project->isVisibleTo($this->staff()));
        $this->assertFalse($project->isVisibleTo($this->userWithPermissions('dashboard.view')), 'No baseline project permission');
    }

    public function test_inactive_user_cannot_gain_visibility(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id, 'visibility' => ProjectVisibility::Company->value]);
        $manager->forceFill(['is_active' => false])->save();

        $this->assertFalse($project->isVisibleTo($manager->fresh()));
        $this->assertSame([], $this->visibleIds($manager->fresh()));
        $this->actingAs($manager)->get(route('projects.show', $project))->assertRedirect(route('login'));
    }

    public function test_management_and_super_admin_see_all_projects(): void
    {
        $this->createProject();
        $this->createProject(['name' => 'Second']);

        $this->assertCount(2, $this->visibleIds(User::factory()->withRole('Management')->create()));
        $this->assertCount(2, $this->visibleIds($this->superAdmin()));
    }

    public function test_former_member_loses_access(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        $membership = app(ManageProjectMembers::class)->add($project, $member->id, $this->projectRole()->id, null);

        app(ManageProjectMembers::class)->remove($membership);

        $this->assertFalse($project->fresh()->isVisibleTo($member));
    }

    public function test_direct_url_access_respects_visibility(): void
    {
        $project = $this->createProject();

        $this->actingAs($this->staff())->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($this->staff())->get(route('projects.edit', $project))->assertForbidden();
    }

    public function test_project_list_only_contains_visible_projects(): void
    {
        $manager = $this->staff();
        $this->createProject(['name' => 'Visible Project', 'project_manager_id' => $manager->id]);
        $this->createProject(['name' => 'Secret Project']);

        $this->actingAs($manager);

        Livewire::test('pages::projects.index')
            ->set('tab', 'all')
            ->assertSee('Visible Project')
            ->assertDontSee('Secret Project');
    }

    public function test_global_search_respects_project_visibility(): void
    {
        $lead = Department::factory()->create();
        $user = $this->memberOf($lead);
        $this->createProject(['name' => 'Alpha Restricted']);
        $this->createProject(['name' => 'Alpha Department Mine', 'lead_department_id' => $lead->id, 'visibility' => ProjectVisibility::Department->value]);
        $this->createProject(['name' => 'Alpha Department Other', 'visibility' => ProjectVisibility::Department->value]);
        $company = $this->createProject(['name' => 'Alpha Company', 'visibility' => ProjectVisibility::Company->value]);
        $restricted = Project::query()->where('name', 'Alpha Restricted')->sole();

        $results = collect(app(GlobalSearch::class)->search($user, 'alpha'))->firstWhere('group', 'Projects');

        $this->assertEqualsCanonicalizing(['Alpha Department Mine', 'Alpha Company'], array_column($results['items'], 'title'));
        $this->assertNull(collect(app(GlobalSearch::class)->search($user, $restricted->number))->firstWhere('group', 'Projects'));
        $this->assertNotNull($company);
    }

    public function test_dashboard_counters_respect_project_visibility(): void
    {
        $manager = $this->staff();
        $this->createProject(['name' => 'Mine', 'project_manager_id' => $manager->id]);
        $this->createProject(['name' => 'Hidden One']);
        $this->createProject(['name' => 'Hidden Two']);

        $this->actingAs($manager);

        $panel = Livewire::test('pages::dashboard')->instance()->projectPanel;

        $this->assertSame('Managed Projects', $panel['title']);
        $this->assertSame(['Mine'], $panel['projects']->pluck('name')->all());
        $this->actingAs($manager)->get(route('dashboard'))->assertSee('Mine')->assertDontSee('Hidden One');
    }

    public function test_changing_visibility_is_audited(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('openVisibility')
            ->set('visibility', ProjectVisibility::Department->value)
            ->call('saveVisibility')
            ->assertHasNoErrors();

        $this->assertSame(ProjectVisibility::Department, $project->fresh()->visibility);
        $log = Activity::query()->where('event', 'project.visibility_changed')->sole();
        $this->assertSame('restricted', $log->attribute_changes['old']['visibility']);
        $this->assertSame('department', $log->attribute_changes['attributes']['visibility']);
        $this->assertSame($manager->id, $log->causer_id);
    }

    public function test_project_manager_cannot_open_project_to_whole_company(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('visibility', ProjectVisibility::Company->value)
            ->call('saveVisibility')
            ->assertForbidden();

        $this->assertSame(ProjectVisibility::Restricted, $project->fresh()->visibility);

        $this->expectException(BusinessRuleException::class);
        app(ChangeProjectVisibility::class)->handle($project, ProjectVisibility::Company, $manager);
    }

    public function test_unauthorized_user_cannot_change_visibility(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        app(ManageProjectMembers::class)->add($project, $member->id, $this->projectRole()->id, null);

        $this->actingAs($member);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('visibility', ProjectVisibility::Department->value)
            ->call('saveVisibility')
            ->assertForbidden();

        $this->assertSame(ProjectVisibility::Restricted, $project->fresh()->visibility);
    }

    public function test_visibility_permission_holder_can_set_company_visibility_on_create(): void
    {
        $creator = $this->userWithPermissions('project.view', 'project.create');

        try {
            $this->createProject(['visibility' => ProjectVisibility::Company->value], $creator);
            $this->fail('Company visibility needs project.manage-visibility.');
        } catch (BusinessRuleException) {
        }

        $creator->givePermissionTo('project.manage-visibility');
        $project = $this->createProject(['visibility' => ProjectVisibility::Company->value], $creator->fresh());

        $this->assertSame(ProjectVisibility::Company, $project->visibility);
    }

    public function test_member_can_view_but_not_edit_or_manage(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        app(ManageProjectMembers::class)->add($project, $member->id, $this->projectRole()->id, null);

        $this->actingAs($member);
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee('Add Member');
        $this->get(route('projects.edit', $project))->assertForbidden();

        Livewire::test('projects.team', ['projectId' => $project->id])->call('addMember')->assertForbidden();
        Livewire::test('projects.team', ['projectId' => $project->id])->call('addDepartment')->assertForbidden();
        Livewire::test('projects.milestones', ['projectId' => $project->id])->call('create')->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $project])->call('openSchedule')->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $project])->call('openProgress')->assertForbidden();
    }

    public function test_project_manager_contextual_rights_exclude_administrative_actions(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->assertFalse($manager->can('project.update'));
        $this->assertTrue($manager->can('update', $project));
        $this->assertTrue($manager->can('manageMembers', $project));
        $this->assertTrue($manager->can('manageMilestones', $project));
        $this->assertTrue($manager->can('changeVisibility', [$project, ProjectVisibility::Department]));
        $this->assertFalse($manager->can('changeVisibility', [$project, ProjectVisibility::Company]));
        $this->assertFalse($manager->can('archive', $project));
        $this->assertFalse($manager->hasRole('Project Manager'));
    }

    public function test_owner_sees_but_does_not_manage_without_permissions(): void
    {
        $owner = $this->staff();
        $project = $this->createProject(['owner_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $project));
        $this->assertFalse($owner->can('update', $project));
        $this->assertFalse($owner->can('manageMembers', $project));
        $this->assertFalse($owner->can('complete', $project));
    }
}
