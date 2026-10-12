<?php

namespace Tests\Feature\Ticket;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Enums\TicketAssignmentRole;
use App\Models\Activity;
use App\Models\Department;
use App\Models\TicketAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketAssignmentTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_leader_assigns_pic_from_ticket_page(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $andi = $this->member($programmer);
        $ticket = $this->acceptTicket($this->createTicket(['current_department_id' => $programmer->id]));

        $this->actingAs($leader);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->set('picUserId', $andi->id)
            ->call('savePic')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $ticket->refresh()->load('status');
        $this->assertSame($andi->id, $ticket->picAssignment()->value('user_id'));
        $this->assertNotNull($ticket->first_assigned_at);
        $this->assertSame('ASSIGNED', $ticket->status->code);
        $this->assertTrue(Activity::query()->where('event', 'ticket.assigned')->exists());
    }

    public function test_pic_must_belong_to_current_department(): void
    {
        $programmer = Department::factory()->create();
        $qa = Department::factory()->create();
        $outsider = $this->member($qa);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->expectException(BusinessRuleException::class);
        app(ManageTicketAssignments::class)->assign($ticket, $outsider->id, $this->superAdmin());
    }

    public function test_only_one_active_pic_is_allowed_by_database(): void
    {
        $programmer = Department::factory()->create();
        $ticket = $this->assignedTicket($programmer, $this->member($programmer));

        $this->expectException(QueryException::class);
        TicketAssignment::query()->create(['ticket_id' => $ticket->id, 'user_id' => $this->member($programmer)->id, 'role' => TicketAssignmentRole::Pic, 'assigned_at' => now()]);
    }

    public function test_second_assign_reports_stale_state(): void
    {
        $programmer = Department::factory()->create();
        $ticket = $this->assignedTicket($programmer, $this->member($programmer));

        $this->expectExceptionMessage('sudah ditugaskan');
        app(ManageTicketAssignments::class)->assign($ticket, $this->member($programmer)->id, $this->superAdmin());
    }

    public function test_pic_change_preserves_history_and_is_logged(): void
    {
        $programmer = Department::factory()->create();
        $budi = $this->member($programmer);
        $andi = $this->member($programmer);
        $ticket = $this->assignedTicket($programmer, $budi);

        app(ManageTicketAssignments::class)->changePic($ticket, $andi->id, $this->superAdmin());

        $this->assertSame($andi->id, $ticket->picAssignment()->value('user_id'));
        $this->assertNotNull($ticket->assignments()->where('user_id', $budi->id)->sole()->ended_at);

        $log = Activity::query()->where('event', 'ticket.pic_changed')->sole();
        $this->assertSame($budi->name, $log->attribute_changes['old']['pic']);
        $this->assertSame($andi->name, $log->attribute_changes['attributes']['pic']);
    }

    public function test_collaborators_come_from_current_department_without_duplicates(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $helper = $this->member($programmer);
        $ticket = $this->assignedTicket($programmer, $pic);
        $assignments = app(ManageTicketAssignments::class);

        $assignments->addCollaborator($ticket, $helper->id, $this->superAdmin());
        $this->assertSame(1, $ticket->collaboratorAssignments()->count());
        $this->assertTrue(Activity::query()->where('event', 'ticket.collaborator_added')->exists());

        foreach ([$helper->id, $pic->id, $this->member(Department::factory()->create())->id] as $invalid) {
            try {
                $assignments->addCollaborator($ticket, $invalid, $this->superAdmin());
                $this->fail('Duplicate, PIC or other-department collaborator must be rejected.');
            } catch (BusinessRuleException) {
            }
        }
    }

    public function test_staff_cannot_assign_or_assign_into_other_department(): void
    {
        $programmer = Department::factory()->create();
        $staff = $this->member($programmer);
        $ticket = $this->acceptTicket($this->createTicket(['current_department_id' => $programmer->id]));

        $this->actingAs($staff);

        Livewire::test('pages::tickets.show', ['ticket' => $ticket])->call('openPic')->assertForbidden();
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])->set('picUserId', $staff->id)->call('savePic')->assertForbidden();
        Livewire::test('tickets.table', ['mode' => 'inbox'])->call('openAssign', $ticket->id)->assertForbidden();

        $this->assertNull($ticket->picAssignment()->first());
    }
}
