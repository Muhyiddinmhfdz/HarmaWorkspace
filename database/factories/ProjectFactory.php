<?php

namespace Database\Factories;

use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Enums\ProjectVisibility;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDepartment;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a valid project (with a lead department) from the seeded project
 * masters. Tests that exercise business rules should use the domain actions.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfDay();
        $end = $start->addDays(60);

        return [
            'number' => 'PRJ-TEST-'.fake()->unique()->numerify('#####'),
            'name' => fake()->unique()->company().' Project',
            'description' => fake()->sentence(),
            'client_id' => null,
            'project_category_id' => fn () => ProjectCategory::query()->firstOrCreate(['code' => 'TEST'], ['name' => 'Test Category'])->id,
            'status_id' => fn () => Status::query()->forScope(MasterScope::Project)->where('is_initial', true)->value('id'),
            'priority_id' => fn () => Priority::query()->forScope(MasterScope::Project)->orderBy('level')->value('id'),
            'health_status_id' => fn () => ProjectHealthStatus::query()->where('is_default', true)->value('id'),
            'owner_id' => null,
            'project_manager_id' => User::factory(),
            'visibility' => ProjectVisibility::Restricted,
            'planned_start' => $start,
            'planned_end' => $end,
            'current_start' => $start,
            'current_end' => $end,
            'progress_mode' => ProgressMode::Manual,
            'progress_percentage' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            if ($project->leadDepartment()->doesntExist()) {
                $lead = new ProjectDepartment(['project_id' => $project->id, 'department_id' => Department::factory()->create()->id]);
                $lead->forceFill(['is_lead' => true])->save();
            }
        });
    }

    public function companyVisible(): static
    {
        return $this->state(fn () => ['visibility' => ProjectVisibility::Company]);
    }
}
