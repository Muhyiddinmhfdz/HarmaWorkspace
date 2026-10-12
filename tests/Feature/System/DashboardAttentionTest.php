<?php

namespace Tests\Feature\System;

use App\Domain\System\Dashboard\NeedsAttention;
use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class DashboardAttentionTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_blocked_and_overdue_tasks_need_attention_with_the_reason(): void
    {
        $pic = $this->staff();
        $department = Department::factory()->create();
        $this->moveTask($this->createTask(['title' => 'API Integration', 'department_id' => $department->id, 'pic_user_id' => $pic->id]), 'TODO', 'IN_PROGRESS', 'BLOCKED');
        $late = $this->moveTask($this->createTask(['title' => 'Stock Report', 'department_id' => $department->id, 'pic_user_id' => $pic->id]), 'TODO');
        $late->forceFill(['current_end' => today()->subDays(2)])->save();
        $this->moveTask($this->createTask(['title' => 'Someone else blocked']), 'TODO', 'IN_PROGRESS', 'BLOCKED');

        $items = collect(app(NeedsAttention::class)->for($pic));

        $blocked = $items->firstWhere('label', 'Blocked Task');
        $this->assertSame('API Integration', $blocked['title']);
        $this->assertSame('Menunggu akses API', $blocked['meta'], 'The blocker reason is visible without opening the task');
        $this->assertSame(['Stock Report'], $items->where('label', 'Overdue Task')->pluck('title')->all());
        $this->assertNotContains('Someone else blocked', $items->pluck('title')->all());
    }

    public function test_report_waiting_for_information_needs_the_requesters_attention(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $requester = $this->staff();
        $ticket = $this->assignedTicket($programmer, $pic, ['requester_user_id' => $requester->id]);
        $status = app(ChangeTicketStatus::class);
        $status->start($ticket, $pic);
        $status->requestInformation($ticket->refresh(), 'Nomor transaksi?', $pic);

        $item = collect(app(NeedsAttention::class)->for($requester))->firstWhere('kind', 'report');

        $this->assertSame('Report waiting for information', $item['label']);
        $this->assertSame($ticket->number, $item['meta']);
    }

    public function test_leader_sees_reports_without_pic_and_staff_does_not(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);
        app(AcceptTicket::class)->handle($ticket, $leader);

        $this->actingAs($leader);
        $dashboard = Livewire::test('pages::dashboard')->assertSee('Department Work')->instance();

        $this->assertSame(1, $dashboard->leaderInbox['counts']['unassigned']);
        $this->assertContains('1 report belum memiliki PIC', array_column($dashboard->attention, 'title'));

        $this->actingAs($this->member($programmer));
        Livewire::test('pages::dashboard')->assertDontSee('Department Work');
    }

    public function test_dashboard_stays_within_a_bounded_number_of_queries(): void
    {
        $department = Department::factory()->create();
        $pic = $this->member($department, leader: true);
        $project = $this->createProject(['project_manager_id' => $pic->id, 'lead_department_id' => $department->id]);

        foreach (range(1, 8) as $number) {
            $this->createTask(['title' => 'Task '.$number, 'project_id' => $project->id, 'department_id' => $department->id, 'pic_user_id' => $pic->id]);
            $this->createTicket(['current_department_id' => $department->id, 'project_id' => $project->id], $this->superAdmin());
        }

        $this->actingAs($pic);
        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(35, $queries, 'Dashboard query count must not grow with the data (no N+1)');
    }
}
