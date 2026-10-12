<?php

namespace Tests\Concerns;

use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CreateProject;
use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectRole;
use App\Models\Status;
use App\Models\User;

/**
 * Builds projects through the real CreateProject action.
 */
trait CreatesProjects
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createProject(array $overrides = [], ?User $actor = null): Project
    {
        $actor ??= $this->superAdmin();

        return app(CreateProject::class)->handle([
            'name' => 'POS Development',
            'project_category_id' => ProjectCategory::query()->firstOrCreate(['code' => 'DEV'], ['name' => 'Development'])->id,
            'priority_id' => $this->projectPriority('NORMAL')->id,
            'project_manager_id' => $overrides['project_manager_id'] ?? User::factory()->withRole('Staff')->create()->id,
            'lead_department_id' => $overrides['lead_department_id'] ?? Department::factory()->create()->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
            ...$overrides,
        ], $actor);
    }

    /**
     * Walk the lifecycle Draft → Planning → Active.
     */
    protected function activateProject(Project $project, ?User $actor = null): Project
    {
        $actor ??= $this->superAdmin();

        foreach (['PLANNING', 'ACTIVE'] as $code) {
            app(ChangeProjectStatus::class)->handle($project->refresh(), $this->projectStatus($code)->id, $actor);
        }

        return $project->refresh();
    }

    protected function projectPriority(string $code): Priority
    {
        return Priority::query()->forScope(MasterScope::Project)->where('code', $code)->firstOrFail();
    }

    protected function projectStatus(string $code): Status
    {
        return Status::query()->forScope(MasterScope::Project)->where('code', $code)->firstOrFail();
    }

    protected function projectRole(string $code = 'MEMBER'): ProjectRole
    {
        return ProjectRole::query()->where('code', $code)->firstOrFail();
    }
}
