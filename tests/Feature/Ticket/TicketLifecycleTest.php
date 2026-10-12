<?php

namespace Tests\Feature\Ticket;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\AddTicketComment;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Ticket\Actions\UpdateTicket;
use App\Enums\CommentKind;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketRejectionReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    private Department $department;

    private User $pic;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->pic = $this->member($this->department);
        $this->requester = $this->staff();
    }

    private function lifecycle(): ChangeTicketStatus
    {
        return app(ChangeTicketStatus::class);
    }

    private function code(Ticket $ticket): string
    {
        return $ticket->refresh()->load('status')->status->code;
    }

    public function test_happy_path_to_closed(): void
    {
        $ticket = $this->createTicket(['current_department_id' => $this->department->id], $this->requester);
        app(ManageTicketAssignments::class)->assign($ticket, $this->pic->id, $this->superAdmin());

        $this->lifecycle()->start($ticket->refresh(), $this->pic);
        $this->assertSame('IN_PROGRESS', $this->code($ticket));
        $startedAt = $ticket->started_at;
        $this->assertNotNull($startedAt);

        $this->lifecycle()->sendToReview($ticket->refresh(), $this->pic);
        $this->assertSame('IN_REVIEW', $this->code($ticket));

        $this->lifecycle()->resolve($ticket->refresh(), $this->resolution()->id, 'Validasi pembayaran diperbaiki.', $this->requester);
        $ticket->refresh();
        $this->assertSame('RESOLVED', $this->code($ticket));
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame($this->requester->id, $ticket->resolved_by);

        $this->lifecycle()->close($ticket, $this->requester);
        $ticket->refresh();
        $this->assertSame('CLOSED', $this->code($ticket));
        $this->assertNotNull($ticket->closed_at);
        $this->assertTrue($startedAt->equalTo($ticket->started_at));
    }

    public function test_revision_loop_keeps_started_at(): void
    {
        $ticket = $this->ticketInReview($this->department, $this->pic, ['requester_user_id' => $this->requester->id]);
        $startedAt = $ticket->started_at;

        try {
            $this->lifecycle()->requestRevision($ticket, '  ', $this->requester);
            $this->fail('Revision requires a reason.');
        } catch (BusinessRuleException) {
        }

        $this->lifecycle()->requestRevision($ticket->refresh(), 'Laporan stok belum diperbarui.', $this->requester);
        $this->assertSame('REVISION', $this->code($ticket));
        $this->assertSame(CommentKind::RevisionRequest, $ticket->comments()->sole()->kind);

        $this->lifecycle()->start($ticket->refresh(), $this->pic);
        $this->assertSame('IN_PROGRESS', $this->code($ticket));
        $this->assertTrue($startedAt->equalTo($ticket->refresh()->started_at), 'started_at is not overwritten');
    }

    public function test_need_information_resumes_previous_status(): void
    {
        $ticket = $this->assignedTicket($this->department, $this->pic, ['requester_user_id' => $this->requester->id]);
        $this->lifecycle()->start($ticket, $this->pic);

        try {
            $this->lifecycle()->requestInformation($ticket->refresh(), '', $this->pic);
            $this->fail('Message required.');
        } catch (BusinessRuleException) {
        }

        $this->lifecycle()->requestInformation($ticket->refresh(), 'Nomor transaksi yang error?', $this->pic);
        $ticket->refresh();
        $this->assertSame('NEED_INFO', $this->code($ticket));
        $this->assertSame($this->ticketStatus('IN_PROGRESS')->id, $ticket->resume_status_id);
        $this->assertSame(CommentKind::InformationRequest, $ticket->comments()->sole()->kind);

        $this->actingAs($this->requester);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->assertSee('Waiting for Information')
            ->assertSee('Nomor transaksi yang error?')
            ->call('perform', 'provide_information')
            ->assertSet('stepOperation', 'provide_information')
            ->set('stepMessage', 'TRX-20261012-001')
            ->call('submitStep')
            ->assertHasNoErrors();

        $ticket->refresh();
        $this->assertSame('IN_PROGRESS', $this->code($ticket));
        $this->assertNull($ticket->resume_status_id);
        $this->assertSame(CommentKind::InformationProvided, $ticket->comments()->latest('id')->first()->kind);
        $this->assertTrue(Activity::query()->where('event', 'ticket.information_requested')->exists());
        $this->assertTrue(Activity::query()->where('event', 'ticket.information_provided')->exists());
    }

    public function test_need_information_from_queue_returns_to_queue(): void
    {
        $ticket = $this->createTicket(['current_department_id' => $this->department->id], $this->requester);
        $this->lifecycle()->requestInformation($ticket, 'Outlet mana?', $this->superAdmin());
        $this->lifecycle()->provideInformation($ticket->refresh(), 'Outlet Bandung', $this->requester);

        $this->assertSame('WAITING_ASSIGNMENT', $this->code($ticket), 'Not hardcoded to In Progress');
    }

    public function test_pic_can_send_to_review_but_not_self_resolve(): void
    {
        $ticket = $this->assignedTicket($this->department, $this->pic, ['requester_user_id' => $this->requester->id]);
        $this->lifecycle()->start($ticket, $this->pic);

        $this->actingAs($this->pic);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket->refresh()])
            ->call('perform', 'send_to_review')
            ->call('submitStep')
            ->assertHasNoErrors();
        $this->assertSame('IN_REVIEW', $this->code($ticket));

        $page = Livewire::test('pages::tickets.show', ['ticket' => $ticket->refresh()]);
        $offered = collect($page->instance()->actions['more'])->pluck('key')->push($page->instance()->actions['primary']['key'] ?? null);
        $this->assertNotContains('resolve', $offered->all());
        $page->call('perform', 'resolve')->assertForbidden();
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->set('resolutionId', (string) $this->resolution()->id)
            ->set('resolutionNotes', 'Selesai')
            ->call('resolve')
            ->assertForbidden();

        $this->assertSame('IN_REVIEW', $this->code($ticket));
    }

    public function test_authorized_reviewer_resolves_with_required_resolution(): void
    {
        $leader = $this->member($this->department, leader: true);
        $ticket = $this->ticketInReview($this->department, $this->pic);

        $this->actingAs($leader);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->assertSet('actions.primary.key', 'resolve')
            ->call('perform', 'resolve')
            ->call('resolve')
            ->assertHasErrors(['resolutionId' => 'required', 'resolutionNotes' => 'required'])
            ->set('resolutionId', (string) $this->resolution()->id)
            ->set('resolutionNotes', 'Bug diperbaiki di release 1.2.')
            ->call('resolve')
            ->assertHasNoErrors();

        $this->assertSame('RESOLVED', $this->code($ticket));
        $this->assertSame($this->resolution()->id, $ticket->refresh()->resolution_id);
    }

    public function test_reopen_requires_reason_and_keeps_history(): void
    {
        $ticket = $this->ticketInReview($this->department, $this->pic, ['requester_user_id' => $this->requester->id]);
        $this->lifecycle()->resolve($ticket, $this->resolution()->id, 'Selesai', $this->requester);
        $this->lifecycle()->close($ticket->refresh(), $this->requester);
        $closedAt = $ticket->refresh()->closed_at;

        try {
            $this->lifecycle()->reopen($ticket, '', $this->requester);
            $this->fail('Reason required.');
        } catch (BusinessRuleException) {
        }

        $this->lifecycle()->reopen($ticket->refresh(), 'Error muncul lagi di outlet lain.', $this->requester);
        $ticket->refresh();

        $this->assertSame('REOPENED', $this->code($ticket));
        $this->assertSame(1, $ticket->reopen_count);
        $this->assertNotNull($ticket->last_reopened_at);
        $this->assertNull($ticket->closed_at);

        $log = Activity::query()->where('event', 'ticket.reopened')->sole();
        $this->assertSame($closedAt->toDateTimeString(), $log->attribute_changes['old']['closed_at']);
        $this->assertSame('Error muncul lagi di outlet lain.', $log->properties['reason']);
        $this->assertTrue(Activity::query()->where('event', 'ticket.closed')->exists(), 'Closure stays in history');

        $this->lifecycle()->start($ticket, $this->pic);
        $this->assertSame('IN_PROGRESS', $this->code($ticket));
    }

    public function test_resolved_ticket_can_be_reopened_directly(): void
    {
        $ticket = $this->ticketInReview($this->department, $this->pic);
        $this->lifecycle()->resolve($ticket, $this->resolution()->id, 'Selesai', $this->superAdmin());
        $this->lifecycle()->reopen($ticket->refresh(), 'Belum sesuai', $this->superAdmin());

        $this->assertSame('REOPENED', $this->code($ticket));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $ticket = $this->createTicket(['current_department_id' => $this->department->id]);

        foreach (['start', 'close'] as $operation) {
            try {
                $this->lifecycle()->{$operation}($ticket->refresh(), $this->superAdmin());
                $this->fail($operation.' from the queue must be rejected.');
            } catch (BusinessRuleException $e) {
                $this->assertStringStartsWith('Status Ticket tidak dapat diubah dari Waiting Assignment', $e->getMessage());
            }
        }

        $this->expectException(BusinessRuleException::class);
        $this->lifecycle()->resolve($ticket->refresh(), $this->resolution()->id, 'x', $this->superAdmin());
    }

    public function test_request_manipulation_cannot_skip_steps(): void
    {
        $leader = $this->member($this->department, leader: true);
        $ticket = $this->assignedTicket($this->department, $this->pic);

        $this->actingAs($leader);

        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->call('perform', 'close')
            ->assertDispatched('notify', type: 'error');

        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->call('perform', 'not_an_operation')
            ->assertNotFound();

        $this->assertSame('ASSIGNED', $this->code($ticket));
    }

    public function test_reject_and_cancel_require_reasons_and_are_final(): void
    {
        $leader = $this->member($this->department, leader: true);
        $ticket = $this->createTicket(['current_department_id' => $this->department->id], $this->requester);
        $reason = TicketRejectionReason::query()->where('code', 'DUPLICATE')->sole();

        try {
            $this->lifecycle()->reject($ticket, $reason->id, '', $leader);
            $this->fail('Notes required.');
        } catch (BusinessRuleException) {
        }

        $this->lifecycle()->reject($ticket->refresh(), $reason->id, 'Sudah dilaporkan di TCK lain.', $leader);
        $ticket->refresh();
        $this->assertSame('REJECTED', $this->code($ticket));
        $this->assertSame($reason->id, $ticket->rejection_reason_id);

        $this->expectException(BusinessRuleException::class);
        app(UpdateTicket::class)->handle($ticket, ['title' => 'x', 'description' => 'y', 'ticket_type_id' => $ticket->ticket_type_id, 'ticket_category_id' => $ticket->ticket_category_id], $this->requester);
    }

    public function test_requester_can_cancel_own_request(): void
    {
        $ticket = $this->createTicket(['current_department_id' => $this->department->id], $this->requester);

        $this->actingAs($this->requester);
        Livewire::test('pages::tickets.show', ['ticket' => $ticket])
            ->call('perform', 'cancel')
            ->call('submitStep')
            ->assertHasErrors(['stepMessage' => 'required'])
            ->set('stepMessage', 'Sudah tidak diperlukan')
            ->call('submitStep')
            ->assertHasNoErrors();

        $this->assertSame('CANCELLED', $this->code($ticket));
    }

    public function test_closed_ticket_cannot_be_edited_or_commented(): void
    {
        $ticket = $this->ticketInReview($this->department, $this->pic, ['requester_user_id' => $this->requester->id]);
        $this->lifecycle()->resolve($ticket, $this->resolution()->id, 'Selesai', $this->requester);
        $this->lifecycle()->close($ticket->refresh(), $this->requester);

        $this->actingAs($this->requester)->get(route('tickets.edit', $ticket))->assertForbidden();

        $this->expectException(BusinessRuleException::class);
        app(AddTicketComment::class)->handle($ticket->refresh(), 'Halo', $this->requester);
    }

    public function test_primary_action_follows_state(): void
    {
        $leader = $this->member($this->department, leader: true);
        $ticket = $this->createTicket(['current_department_id' => $this->department->id]);

        $this->actingAs($leader);
        $this->assertSame('accept', Livewire::test('pages::tickets.show', ['ticket' => $ticket])->instance()->actions['primary']['key']);

        $this->acceptTicket($ticket);
        $this->assertSame('assign', Livewire::test('pages::tickets.show', ['ticket' => $ticket])->instance()->actions['primary']['key']);

        app(ManageTicketAssignments::class)->assign($ticket, $this->pic->id, $leader);
        $this->actingAs($this->pic);
        $this->assertSame('start', Livewire::test('pages::tickets.show', ['ticket' => $ticket])->instance()->actions['primary']['key']);
    }
}
