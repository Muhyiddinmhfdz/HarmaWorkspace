<?php

namespace Tests\Feature\Ticket;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ChangeTicketDeadline;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Support\TicketQueue;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketInboxTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_new_ticket_appears_in_target_department_inbox_only(): void
    {
        $programmer = Department::factory()->create();
        $qa = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $qaStaff = $this->member($qa);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->actingAs($leader);
        Livewire::test('tickets.table', ['mode' => 'inbox'])
            ->assertSet('quick', 'new')
            ->assertSee($ticket->title)
            ->assertSee('Accept');

        $this->actingAs($qaStaff);
        Livewire::test('tickets.table', ['mode' => 'inbox'])->assertDontSee($ticket->title);
    }

    public function test_member_cannot_open_inbox_of_unrelated_department(): void
    {
        $programmer = Department::factory()->create();
        $other = Department::factory()->create();
        $staff = $this->member($other);

        $this->actingAs($staff);

        Livewire::withQueryParams(['department' => $programmer->id])->test('tickets.table', ['mode' => 'inbox'])->assertForbidden();
    }

    public function test_department_leader_accepts_and_ticket_moves_to_unassigned(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->actingAs($leader);
        Livewire::test('tickets.table', ['mode' => 'inbox'])
            ->call('accept', $ticket->id)
            ->assertDispatched('notify', type: 'success');

        $ticket->refresh();
        $this->assertSame($leader->id, $ticket->accepted_by);
        $this->assertNotNull($ticket->accepted_at);
        $this->assertTrue(Activity::query()->where('event', 'ticket.accepted')->exists());

        $queue = app(TicketQueue::class);
        $counts = $queue->counts($queue->base($leader)->where('tickets.current_department_id', $programmer->id), ['new', 'unassigned'], $leader);
        $this->assertSame(['new' => 0, 'unassigned' => 1], $counts);
    }

    public function test_staff_without_contextual_access_cannot_accept(): void
    {
        $programmer = Department::factory()->create();
        $staff = $this->member($programmer);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->actingAs($staff);
        Livewire::test('tickets.table', ['mode' => 'inbox'])
            ->assertSee($ticket->title)
            ->assertDontSee('Accept')
            ->call('accept', $ticket->id)
            ->assertForbidden();

        $this->assertNull($ticket->refresh()->accepted_at);
    }

    public function test_double_accept_is_rejected_with_friendly_message(): void
    {
        $ticket = $this->createTicket();
        app(AcceptTicket::class)->handle($ticket, $this->superAdmin());

        $this->expectExceptionMessage('Muat ulang data terbaru');
        app(AcceptTicket::class)->handle($ticket->refresh(), $this->superAdmin());
    }

    public function test_inbox_counters_are_accurate(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $pic = $this->member($programmer);
        $status = app(ChangeTicketStatus::class);

        $this->createTicket(['current_department_id' => $programmer->id]); // new
        $this->acceptTicket($this->createTicket(['current_department_id' => $programmer->id])); // unassigned
        $this->assignedTicket($programmer, $pic); // assigned
        $working = $this->assignedTicket($programmer, $pic);
        $status->start($working, $pic); // in progress
        $waiting = $this->assignedTicket($programmer, $pic);
        $status->start($waiting, $pic);
        $status->requestInformation($waiting->refresh(), 'Nomor transaksi?', $pic); // waiting
        $this->ticketInReview($programmer, $pic); // review
        $late = $this->assignedTicket($programmer, $pic, ['due_date' => today()->addDay()->toDateString()]);
        $late->forceFill(['current_due_at' => now()->subDay()])->save(); // overdue (assigned)
        $this->createTicket(); // another department

        $this->actingAs($leader);
        $component = Livewire::test('tickets.table', ['mode' => 'inbox']);

        $this->assertEquals(
            ['new' => 1, 'unassigned' => 1, 'assigned' => 2, 'in_progress' => 1, 'waiting' => 1, 'review' => 1, 'overdue' => 1, 'all' => 7],
            $component->instance()->counts,
        );
    }

    public function test_unassigned_definition_ignores_tickets_with_pic(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $ticket = $this->assignedTicket($programmer, $pic);
        $admin = $this->superAdmin();
        $queue = app(TicketQueue::class);

        $this->assertNotNull($ticket->accepted_at, 'Assigning implies acceptance');
        $this->assertSame(0, $queue->counts($queue->base($admin), ['unassigned'], $admin)['unassigned']);
    }

    public function test_deadline_baseline_change_and_overdue(): void
    {
        $ticket = $this->createTicket(['due_date' => today()->addDays(5)->toDateString()]);
        $deadline = app(ChangeTicketDeadline::class);

        $this->assertTrue($ticket->planned_due_at->equalTo($ticket->current_due_at));

        try {
            $deadline->handle($ticket, today()->addDays(9)->toDateString(), null, $this->superAdmin());
            $this->fail('Reason required once a baseline exists.');
        } catch (BusinessRuleException) {
        }

        $deadline->handle($ticket->refresh(), today()->addDays(9)->toDateString(), 'Menunggu vendor', $this->superAdmin());
        $ticket->refresh();

        $this->assertSame(today()->addDays(5)->toDateString(), $ticket->planned_due_at->toDateString());
        $this->assertSame(today()->addDays(9)->toDateString(), $ticket->current_due_at->toDateString());
        $this->assertSame('Menunggu vendor', $ticket->scheduleChanges()->sole()->reason);

        $ticket->forceFill(['current_due_at' => now()->subMinute()])->save();
        $this->assertTrue($ticket->refresh()->isOverdue());
        $this->assertSame(1, Ticket::query()->overdue()->count());
    }
}
