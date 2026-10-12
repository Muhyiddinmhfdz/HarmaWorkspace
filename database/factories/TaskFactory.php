<?php

namespace Database\Factories;

use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Standalone task from the seeded task masters. Business-rule tests should use
 * the CreateTask action instead.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TSK-TEST-'.fake()->unique()->numerify('#####'),
            'title' => ucfirst(implode(' ', (array) fake()->words(3))),
            'project_id' => null,
            'status_id' => fn () => Status::query()->forScope(MasterScope::Task)->where('is_initial', true)->value('id'),
            'priority_id' => fn () => Priority::query()->forScope(MasterScope::Task)->orderBy('level')->value('id'),
            'department_id' => Department::factory(),
            'progress_percentage' => 0,
        ];
    }
}
