<?php

namespace Tests\Feature\Ticket;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Ticket\Actions\TransferTicket;
use App\Models\Activity;
use App\Models\Department;
use App\Models\TicketTransferReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketTransferTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    private function reason(): TicketTransferReason
    {
        return TicketTransferReason::query()->where('code', 'WRONG_DEPARTMENT')->sole();
    }

    public function test_leader_transfers_and_ownership_moves_completely(): void
    {
        $programmer = Department::factory()->create(['name' => 'Programmer']);
        $qa = Department::factory()->create(['name' => 'QA']);
        $leader = $this->member($programmer, leader: true);
        $pic = $this->member($programmer);
        $helper = $this->member($programmer);
        $ticket = $this->assignedTicket($programmer, $pic);
        app(ManageTicketAssignments::class)->addCollaborator($ticket, $helper->id, $this->superAdmin());

        $this->actingAs($leader);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->set('transferDepartmentId', $qa->id)
            ->set('transferReasonId', (string) $this->reason()->id)
            ->set('transferNotes', 'Perlu pengujian ulang oleh QA')
            ->call('transfer')
            ->assertHasNoErrors()
            ->assertRedirect(route('tickets.inbox'));

        $ticket->refresh()->load('status');
        $this->assertSame($qa->id, $ticket->current_department_id);
        $this->assertNull($ticket->accepted_at);
        $this->assertNull($ticket->accepted_by);
        $this->assertTrue($ticket->status->is_initial, 'Back to the queue of the new department');
        $this->assertSame(0, $ticket->activeAssignments()->count(), 'PIC and collaborators are released');
        $this->assertSame(2, $ticket->assignments()->count(), 'History is kept');

        $transfer = $ticket->transfers()->sole();
        $this->assertSame($programmer->id, $transfer->from_department_id);
        $this->assertSame('Perlu pengujian ulang oleh QA', $transfer->notes);
        $this->assertTrue(Activity::query()->where('event', 'ticket.department_transferred')->exists());

        // The new department sees it as new; the old department loses access.
        $qaLeader = $this->member($qa, leader: true);
        $this->actingAs($qaLeader);
        Livewire::test('tickets.table', ['mode' => 'inbox'])->assertSee($ticket->title);

        $this->assertFalse($ticket->isVisibleTo($leader->fresh()));
        $this->assertFalse($ticket->isVisibleTo($pic->fresh()));
    }

    public function test_reason_is_required(): void
    {
        $programmer = Department::factory()->create();
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->expectException(BusinessRuleException::class);
        app(TransferTicket::class)->handle($ticket, Department::factory()->create()->id, null, null, $this->superAdmin());
    }

    public function test_cannot_transfer_to_same_or_inactive_department(): void
    {
        $programmer = Department::factory()->create();
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);
        $transfer = app(TransferTicket::class);

        try {
            $transfer->handle($ticket, $programmer->id, $this->reason()->id, null, $this->superAdmin());
            $this->fail('Same department must be rejected.');
        } catch (BusinessRuleException) {
        }

        $this->expectException(BusinessRuleException::class);
        $transfer->handle($ticket, Department::factory()->create(['is_active' => false])->id, $this->reason()->id, null, $this->superAdmin());
    }

    public function test_unauthorized_user_cannot_transfer(): void
    {
        $programmer = Department::factory()->create();
        $staff = $this->member($programmer);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->actingAs($staff);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->set('transferDepartmentId', Department::factory()->create()->id)
            ->set('transferReasonId', (string) $this->reason()->id)
            ->call('transfer')
            ->assertForbidden();

        $this->assertSame($programmer->id, $ticket->refresh()->current_department_id);
    }

    public function test_closed_ticket_cannot_be_transferred(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $ticket = $this->ticketInReview($programmer, $pic);
        $status = app(ChangeTicketStatus::class);
        $status->resolve($ticket, $this->resolution()->id, 'Selesai', $this->superAdmin());

        $this->expectException(BusinessRuleException::class);
        app(TransferTicket::class)->handle($ticket->refresh(), Department::factory()->create()->id, $this->reason()->id, null, $this->superAdmin());
    }
}
