<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\CreateProject;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Numbering\NumberGenerator;
use App\Domain\System\Settings\SettingKey;
use App\Domain\System\Settings\Settings;
use App\Enums\MasterScope;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    public function test_authorized_user_can_create_project_through_the_wizard(): void
    {
        $manager = User::factory()->withRole('Staff')->create();
        $lead = Department::factory()->create();
        $support = Department::factory()->create();
        $client = Client::factory()->create();
        $category = ProjectCategory::query()->create(['code' => 'DEV', 'name' => 'Development']);

        $this->actingAs($this->userWithPermissions('project.view', 'project.create'));

        Livewire::test('pages::projects.create')
            ->set('name', 'POS Development')
            ->set('client_id', (string) $client->id)
            ->set('project_category_id', (string) $category->id)
            ->set('priority_id', (string) $this->projectPriority('HIGH')->id)
            ->call('next')->assertSet('step', 2)
            ->set('project_manager_id', $manager->id)
            ->set('lead_department_id', (string) $lead->id)
            ->set('supporting_department_ids', [(string) $support->id])
            ->call('next')->assertSet('step', 3)
            ->set('start_date', '2026-11-01')
            ->set('end_date', '2026-12-31')
            ->call('next')->assertSet('step', 4)
            ->assertSee('POS Development')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $project = Project::query()->with(['leadDepartment', 'departments'])->sole();

        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-0001$/', $project->number);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame($manager->id, $project->project_manager_id);
        $this->assertSame($lead->id, $project->leadDepartment->department_id);
        $this->assertCount(2, $project->departments);
        $this->assertTrue($project->status->is_initial);
        $this->assertSame('2026-11-01', $project->planned_start->toDateString());
        $this->assertTrue($project->planned_start->equalTo($project->current_start));
        $this->assertTrue($project->planned_end->equalTo($project->current_end));
        $this->assertNull($project->actual_start);
        $this->assertTrue(Activity::query()->where('event', 'project.created')->where('subject_id', $project->id)->exists());
    }

    public function test_staff_without_create_permission_cannot_create_projects(): void
    {
        $this->actingAs($this->staff())->get(route('projects.create'))->assertForbidden();
    }

    public function test_wizard_validates_each_step(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::projects.create')
            ->set('name', '')
            ->set('project_category_id', '')
            ->call('next')
            ->assertHasErrors(['name' => 'required', 'project_category_id' => 'required'])
            ->assertSet('step', 1);

        Livewire::test('pages::projects.create')
            ->set('step', 2)
            ->set('project_manager_id', null)
            ->set('lead_department_id', '')
            ->call('next')
            ->assertHasErrors(['project_manager_id' => 'required', 'lead_department_id' => 'required']);

        Livewire::test('pages::projects.create')
            ->set('step', 3)
            ->set('start_date', '2026-12-31')
            ->set('end_date', '2026-12-01')
            ->call('next')
            ->assertHasErrors(['end_date' => 'after_or_equal']);
    }

    public function test_internal_project_can_exist_without_client(): void
    {
        $project = $this->createProject(['client_id' => null]);

        $this->assertNull($project->client_id);
        $this->actingAs($this->superAdmin())->get(route('projects.show', $project))->assertSee('Internal Project');
    }

    public function test_project_numbers_are_unique_and_sequential(): void
    {
        $first = $this->createProject();
        $second = $this->createProject(['name' => 'HRIS']);

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringEndsWith('0001', $first->number);
        $this->assertStringEndsWith('0002', $second->number);

        $this->expectException(QueryException::class);
        Project::factory()->create(['number' => $first->number]);
    }

    public function test_number_generator_follows_settings_and_never_reuses_numbers(): void
    {
        $generator = app(NumberGenerator::class);

        $year = now()->year;
        $this->assertSame("PRJ-{$year}-0001", $generator->next('project', SettingKey::ProjectNumberPrefix));
        $this->assertSame("PRJ-{$year}-0002", $generator->next('project', SettingKey::ProjectNumberPrefix));

        Setting::query()->create(['key' => SettingKey::NumberPadding->value, 'value' => 6]);
        app(Settings::class)->flush();

        $this->assertSame("PRJ-{$year}-000003", $generator->next('project', SettingKey::ProjectNumberPrefix));
    }

    public function test_project_requires_manager_and_lead_department(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->createProject(['project_manager_id' => User::factory()->inactive()->create()->id]);
    }

    public function test_lead_department_cannot_be_supporting_too(): void
    {
        $department = Department::factory()->create();

        $this->expectException(BusinessRuleException::class);

        $this->createProject(['lead_department_id' => $department->id, 'supporting_department_ids' => [$department->id]]);
    }

    public function test_project_cannot_use_task_or_ticket_scoped_priority(): void
    {
        $taskPriority = Priority::query()->create(['scope' => MasterScope::Task, 'code' => 'HIGH', 'name' => 'High', 'level' => 30]);

        $this->expectException(BusinessRuleException::class);

        $this->createProject(['priority_id' => $taskPriority->id]);
    }

    public function test_inactive_masters_cannot_be_selected_for_new_project(): void
    {
        $inactiveCategory = ProjectCategory::query()->create(['code' => 'OLD', 'name' => 'Old']);
        $inactiveCategory->forceFill(['is_active' => false])->save();

        $this->expectException(BusinessRuleException::class);

        $this->createProject(['project_category_id' => $inactiveCategory->id]);
    }

    public function test_inactive_department_cannot_lead_a_new_project(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->createProject(['lead_department_id' => Department::factory()->inactive()->create()->id]);
    }

    public function test_mass_assignment_cannot_set_protected_project_attributes(): void
    {
        $project = $this->createProject();

        $this->expectException(MassAssignmentException::class);

        $project->fill(['number' => 'HACK-1', 'status_id' => 999, 'progress_percentage' => 100, 'project_manager_id' => 1]);
    }

    public function test_concurrent_style_creation_keeps_numbers_unique(): void
    {
        $numbers = collect(range(1, 5))->map(fn () => $this->createProject(['name' => fake()->word()])->number);

        $this->assertSame(5, $numbers->unique()->count());
        $this->assertSame(5, (int) DB::table('number_sequences')->where('scope', 'project')->value('last_number'));
    }

    public function test_create_project_action_is_reachable_without_the_ui(): void
    {
        $project = app(CreateProject::class)->handle([
            'name' => 'API ready',
            'project_category_id' => ProjectCategory::query()->create(['code' => 'X', 'name' => 'X'])->id,
            'priority_id' => $this->projectPriority('LOW')->id,
            'project_manager_id' => User::factory()->create()->id,
            'lead_department_id' => Department::factory()->create()->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ], $this->superAdmin());

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'API ready']);
    }
}
