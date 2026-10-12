<?php

namespace Tests\Feature\System;

use App\Models\Project;
use Database\Seeders\Development\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seeder_builds_demo_data_through_domain_actions(): void
    {
        $this->superAdmin();

        $this->seed(DevelopmentSeeder::class);

        $this->assertSame(3, Project::query()->count());
        $this->assertSame(3, Project::query()->whereHas('leadDepartment')->count());
        $this->assertTrue(Project::query()->whereHas('milestones')->exists());

        $this->seed(DevelopmentSeeder::class);
        $this->assertSame(3, Project::query()->count(), 'Seeder is idempotent');
    }
}
