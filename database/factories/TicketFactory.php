<?php

namespace Database\Factories;

use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Standalone ticket from the seeded ticket masters. Business-rule tests should
 * use the CreateTicket action instead.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TCK-TEST-'.fake()->unique()->numerify('#####'),
            'title' => ucfirst(implode(' ', (array) fake()->words(4))),
            'description' => fake()->sentence(),
            'requester_user_id' => User::factory(),
            'created_by' => fn (array $attributes) => $attributes['requester_user_id'],
            'ticket_type_id' => fn () => TicketType::query()->firstOrCreate(['code' => 'BUG'], ['name' => 'Bug'])->id,
            'ticket_category_id' => fn () => TicketCategory::query()->firstOrCreate(['code' => 'APP'], ['name' => 'Application'])->id,
            'status_id' => fn () => Status::query()->forScope(MasterScope::Ticket)->where('is_initial', true)->value('id'),
            'priority_id' => fn () => Priority::query()->forScope(MasterScope::Ticket)->orderBy('level')->value('id'),
            'current_department_id' => Department::factory(),
        ];
    }
}
