<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentMembership>
 */
class DepartmentMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'department_id' => Department::factory(),
            'position_id' => null,
            'is_primary' => false,
            'is_leader' => false,
            'joined_at' => now()->toDateString(),
            'ended_at' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['is_primary' => true]);
    }

    public function leader(): static
    {
        return $this->state(fn (array $attributes) => ['is_leader' => true]);
    }
}
