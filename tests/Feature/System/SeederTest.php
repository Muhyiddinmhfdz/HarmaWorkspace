<?php

namespace Tests\Feature\System;

use App\Domain\Approval\Support\ApprovalInbox;
use App\Domain\Ticket\Support\TicketQueue;
use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Models\ApprovalTemplate;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketResolution;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\Development\DevelopmentSeeder;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seeder_builds_demo_data_through_domain_actions(): void
    {
        $admin = $this->superAdmin();

        $this->seed(DevelopmentSeeder::class);

        $this->assertSame(3, Project::query()->count());
        $this->assertSame(3, Project::query()->whereHas('leadDepartment')->count());
        $this->assertTrue(Project::query()->whereHas('milestones')->exists());
        $this->assertSame(6, Task::query()->count());
        $pos = Project::query()->where('name', 'POS Development')->sole();
        $this->assertSame(ProgressMode::TaskWeighted, $pos->progress_mode);
        $this->assertGreaterThan(0, $pos->progress_percentage);

        $this->assertSame(9, Ticket::query()->count());
        $queue = app(TicketQueue::class);
        $counts = $queue->counts($queue->base($admin), ['new', 'unassigned', 'assigned', 'in_progress', 'waiting', 'review', 'resolved', 'overdue'], $admin);
        $this->assertSame(['new' => 1, 'unassigned' => 1, 'assigned' => 1, 'in_progress' => 2, 'waiting' => 1, 'review' => 2, 'resolved' => 1, 'overdue' => 1], $counts);

        // Demo approval: the bug-workflow ticket waits for the DEV leader.
        $this->assertSame(2, ApprovalTemplate::query()->count());
        $this->assertSame(5, Workflow::query()->count(), '3 defaults + 2 demo workflows');
        $andi = User::query()->where('username', 'andi')->sole();
        $this->assertSame(1, app(ApprovalInbox::class)->countFor($andi));

        $this->seed(DevelopmentSeeder::class);
        $this->assertSame(3, Project::query()->count(), 'Seeder is idempotent');
        $this->assertSame(9, Ticket::query()->count());
        $this->assertSame(5, Workflow::query()->count());
    }

    public function test_system_seeder_ticket_masters_are_idempotent_and_respect_admin_changes(): void
    {
        $status = Status::query()->forScope(MasterScope::Ticket)->where('code', 'NEED_INFO')->sole();
        $status->forceFill(['name' => 'Menunggu Info', 'color' => 'yellow'])->save();
        TicketResolution::query()->where('code', 'FIXED')->sole()->forceFill(['name' => 'Diperbaiki'])->save();
        $before = Status::query()->forScope(MasterScope::Ticket)->count();

        $this->seed(SystemSeeder::class);

        $this->assertSame($before, Status::query()->forScope(MasterScope::Ticket)->count());
        $this->assertSame('Menunggu Info', $status->fresh()->name);
        $this->assertSame('Diperbaiki', TicketResolution::query()->where('code', 'FIXED')->value('name'));
        $this->assertSame(0, Ticket::query()->count(), 'No demo tickets in the SystemSeeder');
        $this->assertSame(['GENERAL'], TicketType::query()->pluck('code')->all(), 'Only the neutral production default, no demo catalogue');
    }
}
