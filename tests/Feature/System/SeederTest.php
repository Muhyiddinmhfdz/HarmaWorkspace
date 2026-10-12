<?php

namespace Tests\Feature\System;

use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\Task;
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
        $this->assertSame(6, Task::query()->count());
        $pos = Project::query()->where('name', 'POS Development')->sole();
        $this->assertSame(ProgressMode::TaskWeighted, $pos->progress_mode);
        $this->assertGreaterThan(0, $pos->progress_percentage);

        $this->seed(DevelopmentSeeder::class);
        $this->assertSame(3, Project::query()->count(), 'Seeder is idempotent');
    }
}
