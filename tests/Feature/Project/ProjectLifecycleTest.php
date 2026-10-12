<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\ChangeProjectManager;
use App\Domain\Project\Actions\ChangeProjectSchedule;
use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Project\Actions\UpdateProjectHealth;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Search\GlobalSearch;
use App\Enums\MasterScope;
use App\Models\Activity;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectLifecycleTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    public function test_schedule_change_keeps_baseline_and_creates_history(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);

        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('openSchedule')
            ->set('scheduleEnd', '2026-12-05')
            ->set('scheduleReason', '')
            ->call('saveSchedule')
            ->assertHasErrors(['scheduleReason' => 'required'])
            ->set('scheduleReason', 'Menunggu persetujuan desain client')
            ->call('saveSchedule')
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertSame('2026-11-30', $project->planned_end->toDateString());
        $this->assertSame('2026-12-05', $project->current_end->toDateString());

        $change = $project->scheduleChanges()->sole();
        $this->assertSame('2026-11-30', $change->old_end->toDateString());
        $this->assertSame('2026-12-05', $change->new_end->toDateString());
        $this->assertSame($manager->id, $change->changed_by);
        $this->assertSame('Menunggu persetujuan desain client', $change->reason);
        $this->assertTrue(Activity::query()->where('event', 'project.schedule_changed')->exists());
    }

    public function test_schedule_end_cannot_precede_start(): void
    {
        $project = $this->createProject();

        $this->expectException(BusinessRuleException::class);
        app(ChangeProjectSchedule::class)->handle($project, '2026-12-10', '2026-12-01', 'Salah input', $this->superAdmin());
    }

    public function test_project_manager_change_creates_activity(): void
    {
        $project = $this->createProject();
        $newManager = User::factory()->create(['name' => 'Budi Baru']);

        app(ChangeProjectManager::class)->handle($project, $newManager->id, $this->superAdmin());

        $this->assertSame($newManager->id, $project->fresh()->project_manager_id);
        $log = Activity::query()->where('event', 'project.manager_changed')->sole();
        $this->assertSame('Budi Baru', $log->attribute_changes['attributes']['project_manager']);
    }

    public function test_progress_update_is_recorded_and_validated(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);
        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('openProgress')
            ->set('progress', 150)
            ->call('saveProgress')
            ->assertHasErrors(['progress' => 'max'])
            ->set('progress', 60)
            ->call('saveProgress')
            ->assertHasNoErrors();

        $this->assertSame(60, $project->fresh()->progress_percentage);
        $this->assertStringContainsString('0% → 60%', Activity::query()->where('event', 'project.progress_changed')->sole()->description);
    }

    public function test_health_is_manual_and_audited(): void
    {
        $project = $this->createProject();
        $atRisk = ProjectHealthStatus::query()->where('code', 'AT_RISK')->firstOrFail();

        app(UpdateProjectHealth::class)->handle($project, $atRisk->id, 'Vendor terlambat', $this->superAdmin());

        $this->assertSame($atRisk->id, $project->fresh()->health_status_id);
        $this->assertSame('Vendor terlambat', Activity::query()->where('event', 'project.health_changed')->sole()->properties['note']);
    }

    public function test_status_change_records_actual_start_and_rejects_foreign_scope(): void
    {
        $project = $this->createProject();
        $actor = $this->superAdmin();

        app(ChangeProjectStatus::class)->handle($project, $this->projectStatus('PLANNING')->id, $actor);
        $this->assertNotNull($project->fresh()->actual_start);

        $taskStatus = Status::query()->create(['scope' => MasterScope::Ticket, 'code' => 'OPEN', 'name' => 'Open']);

        $this->expectException(BusinessRuleException::class);
        app(ChangeProjectStatus::class)->handle($project->fresh(), $taskStatus->id, $actor);
    }

    public function test_completion_requires_dedicated_action(): void
    {
        $project = $this->createProject();

        $this->expectException(BusinessRuleException::class);
        app(ChangeProjectStatus::class)->handle($project, $this->projectStatus('COMPLETED')->id, $this->superAdmin());
    }

    public function test_complete_project_sets_actual_end_progress_and_health(): void
    {
        $project = $this->activateProject($this->createProject(['start_date' => today()->subMonth()->toDateString()]));
        $actor = $this->superAdmin();

        app(CompleteProject::class)->handle($project, $actor);
        $project->refresh();

        $this->assertTrue($project->status->counts_as_completed);
        $this->assertNotNull($project->actual_end);
        $this->assertNotNull($project->completed_at);
        $this->assertSame(100, $project->progress_percentage);
        $this->assertTrue($project->health->represents_completion);

        $this->expectException(BusinessRuleException::class);
        app(CompleteProject::class)->handle($project, $actor);
    }

    public function test_only_finished_projects_can_be_archived_and_archived_projects_are_read_only(): void
    {
        $project = $this->createProject(['start_date' => today()->subWeek()->toDateString()]);
        $actor = $this->superAdmin();
        $archive = app(ArchiveProject::class);

        try {
            $archive->archive($project, $actor);
            $this->fail('Active project must not be archivable.');
        } catch (BusinessRuleException) {
        }

        app(CompleteProject::class)->handle($this->activateProject($project), $actor);
        $archive->archive($project->fresh(), $actor);
        $this->assertTrue($project->fresh()->isArchived());

        $this->expectException(BusinessRuleException::class);
        app(UpdateProjectProgress::class)->handle($project->fresh(), 50, $actor);
    }

    public function test_archived_projects_are_hidden_from_active_list_but_searchable(): void
    {
        $project = $this->createProject(['name' => 'Old Archive', 'start_date' => today()->subWeek()->toDateString()]);
        $admin = $this->superAdmin();
        app(CompleteProject::class)->handle($this->activateProject($project), $admin);
        app(ArchiveProject::class)->archive($project->fresh(), $admin);

        $this->actingAs($admin);

        Livewire::test('pages::projects.index')->assertDontSee('Old Archive');
        Livewire::test('pages::projects.index')->set('tab', 'archived')->assertSee('Old Archive');

        $this->assertNotNull(collect(app(GlobalSearch::class)->search($admin, 'Old Archive'))->firstWhere('group', 'Projects'));
    }

    public function test_user_without_permission_cannot_change_status_or_archive(): void
    {
        $member = $this->staff();
        $project = $this->createProject(['visibility' => 'company']);

        $this->actingAs($member);

        Livewire::test('pages::projects.show', ['project' => $project])->call('moveToStatus', $this->projectStatus('PLANNING')->id)->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $project])->call('openVisibility')->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $project])->call('toggleArchive')->assertForbidden();
    }
}
