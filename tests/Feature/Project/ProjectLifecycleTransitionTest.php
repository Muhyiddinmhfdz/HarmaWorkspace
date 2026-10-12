<?php

namespace Tests\Feature\Project;

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\MasterScope;
use App\Models\Project;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjects;
use Tests\TestCase;

class ProjectLifecycleTransitionTest extends TestCase
{
    use CreatesProjects, RefreshDatabase;

    private function move(Project $project, string $code): Project
    {
        return app(ChangeProjectStatus::class)->handle($project->refresh(), $this->projectStatus($code)->id, $this->superAdmin());
    }

    public function test_valid_lifecycle_path(): void
    {
        $project = $this->createProject(['start_date' => today()->subMonth()->toDateString()]);

        $this->assertSame('PLANNING', $this->move($project, 'PLANNING')->status->code);
        $this->assertSame('ACTIVE', $this->move($project, 'ACTIVE')->status->code);
        $this->assertSame('ON_HOLD', $this->move($project, 'ON_HOLD')->status->code);
        $this->assertSame('ACTIVE', $this->move($project, 'ACTIVE')->status->code);

        app(CompleteProject::class)->handle($project->refresh(), $this->superAdmin());
        $this->assertTrue($project->refresh()->status->counts_as_completed);

        app(ArchiveProject::class)->archive($project, $this->superAdmin());
        $this->assertTrue($project->refresh()->isArchived());
    }

    public function test_draft_can_be_cancelled_and_cancelled_can_be_archived(): void
    {
        $project = $this->move($this->createProject(), 'CANCELLED');

        app(ArchiveProject::class)->archive($project, $this->superAdmin());

        $this->assertTrue($project->refresh()->isArchived());
    }

    public function test_invalid_transition_is_rejected_with_friendly_message(): void
    {
        $project = $this->activateProject($this->createProject(['start_date' => today()->subWeek()->toDateString()]));
        app(CompleteProject::class)->handle($project, $this->superAdmin());

        try {
            $this->move($project, 'PLANNING');
            $this->fail('Completed → Planning must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Status Project tidak dapat diubah dari Completed ke Planning.', $e->getMessage());
        }
    }

    public function test_draft_cannot_jump_to_active_or_be_completed(): void
    {
        $project = $this->createProject();

        try {
            $this->move($project, 'ACTIVE');
            $this->fail('Draft → Active must be rejected.');
        } catch (BusinessRuleException) {
        }

        $this->expectException(BusinessRuleException::class);
        app(CompleteProject::class)->handle($project->refresh(), $this->superAdmin());
    }

    public function test_request_manipulation_cannot_bypass_lifecycle(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('moveToStatus', $this->projectStatus('ACTIVE')->id)
            ->assertDispatched('notify', type: 'error', message: 'Status Project tidak dapat diubah dari Draft ke Active.');

        $this->assertSame('DRAFT', $project->refresh()->status->code);
    }

    public function test_non_project_scoped_status_is_rejected(): void
    {
        $project = $this->createProject();
        $taskStatus = Status::query()->create(['scope' => MasterScope::Task, 'code' => 'PLANNING', 'name' => 'Task Planning']);

        $this->expectException(BusinessRuleException::class);
        app(ChangeProjectStatus::class)->handle($project, $taskStatus->id, $this->superAdmin());
    }

    public function test_ui_offers_only_valid_actions(): void
    {
        $project = $this->activateProject($this->createProject());

        $this->actingAs($this->superAdmin());

        $labels = collect(Livewire::test('pages::projects.show', ['project' => $project])->instance()->transitions)->pluck('label')->all();

        $this->assertSame(['Put on hold', 'Cancel project'], $labels);
        $this->assertTrue(Livewire::test('pages::projects.show', ['project' => $project])->instance()->canCompleteNow);
    }

    public function test_archived_project_remains_read_only(): void
    {
        $project = $this->activateProject($this->createProject(['start_date' => today()->subWeek()->toDateString()]));
        app(CompleteProject::class)->handle($project, $this->superAdmin());
        app(ArchiveProject::class)->archive($project->refresh(), $this->superAdmin());

        $this->expectException(BusinessRuleException::class);
        app(ManageProjectMembers::class)->add($project->refresh(), $this->staff()->id, $this->projectRole()->id, null);
    }

    public function test_archive_requires_permission_even_for_project_manager(): void
    {
        $manager = $this->staff();
        $project = $this->activateProject($this->createProject(['project_manager_id' => $manager->id, 'start_date' => today()->subWeek()->toDateString()]));
        app(CompleteProject::class)->handle($project, $this->superAdmin());

        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])->call('toggleArchive')->assertForbidden();
        $this->assertFalse($project->refresh()->isArchived());
    }
}
