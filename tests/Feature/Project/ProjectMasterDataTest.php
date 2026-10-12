<?php

namespace Tests\Feature\Project;

use App\Enums\MasterScope;
use App\Models\Activity;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectMasterDataTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    public function test_master_manager_can_create_project_category(): void
    {
        $this->actingAs($this->userWithPermissions('master-data.view', 'master-data.manage'));

        Livewire::test('pages::master-data.manage', ['type' => 'project-categories'])
            ->call('create')
            ->set('form.code', 'maint')
            ->set('form.name', 'Maintenance')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('project_categories', ['code' => 'MAINT', 'name' => 'Maintenance']);
        $this->assertTrue(Activity::query()->where('event', 'project_category.created')->exists());
    }

    public function test_status_codes_are_unique_per_scope_only(): void
    {
        Status::query()->create(['scope' => MasterScope::Task, 'code' => 'REVIEW', 'name' => 'Review']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::master-data.manage', ['type' => 'project-statuses'])
            ->call('create')
            ->set('form.code', 'REVIEW')
            ->set('form.name', 'In Review')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::master-data.manage', ['type' => 'project-statuses'])
            ->call('create')
            ->set('form.code', 'REVIEW')
            ->set('form.name', 'Duplicate')
            ->call('save')
            ->assertHasErrors(['form.code' => 'unique']);

        $this->assertSame(MasterScope::Project, Status::query()->where('name', 'In Review')->sole()->scope);
    }

    public function test_generic_page_only_lists_project_scoped_records(): void
    {
        Status::query()->create(['scope' => MasterScope::Ticket, 'code' => 'TICKET_ONLY', 'name' => 'Ticket Only']);

        $this->actingAs($this->superAdmin())
            ->get(route('master-data.manage', 'project-statuses'))
            ->assertSee('Draft')
            ->assertDontSee('Ticket Only');
    }

    public function test_only_one_initial_status_and_default_health(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::master-data.manage', ['type' => 'project-statuses'])
            ->call('create')
            ->set('form.code', 'NEW')
            ->set('form.name', 'New')
            ->set('form.is_initial', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['NEW'], Status::query()->forScope(MasterScope::Project)->where('is_initial', true)->pluck('code')->all());

        $onTrack = ProjectHealthStatus::query()->where('code', 'AT_RISK')->firstOrFail();
        Livewire::test('pages::master-data.manage', ['type' => 'project-health'])
            ->call('edit', $onTrack->id)
            ->set('form.is_default', true)
            ->call('save');

        $this->assertSame(['AT_RISK'], ProjectHealthStatus::query()->where('is_default', true)->pluck('code')->all());
    }

    public function test_used_master_code_is_locked_and_master_is_deactivated_not_deleted(): void
    {
        $project = $this->createProject();
        $priority = $project->priority;

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::master-data.manage', ['type' => 'project-priorities'])
            ->call('edit', $priority->id)
            ->set('form.code', 'CHANGED')
            ->call('save')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame('NORMAL', $priority->fresh()->code);

        Livewire::test('pages::master-data.manage', ['type' => 'project-priorities'])
            ->call('confirmStatus', $priority->id)
            ->call('changeStatus');

        $this->assertFalse($priority->fresh()->is_active);
        $this->assertSame($priority->id, $project->fresh()->priority_id);
    }

    public function test_view_only_user_cannot_manage_masters(): void
    {
        $this->actingAs($this->userWithPermissions('master-data.view'));

        Livewire::test('pages::master-data.manage', ['type' => 'project-roles'])->call('create')->assertForbidden();
        $this->get(route('master-data.manage', 'unknown-type'))->assertNotFound();
    }
}
