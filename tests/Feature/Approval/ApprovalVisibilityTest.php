<?php

namespace Tests\Feature\Approval;

use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Models\ApprovalRequest;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class ApprovalVisibilityTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    private function pendingApproval(Department $department, array $steps): ApprovalRequest
    {
        $template = $this->approvalTemplate($steps, 'ticket');
        $this->ticketResolveWithApproval($template);
        $pic = $this->member($department);
        $requester = $this->staff();
        $ticket = $this->ticketInReview($department, $pic, ['requester_user_id' => $requester->id]);
        $resolve = $this->transitionIn($ticket->workflowVersion()->sole(), 'IN_REVIEW', 'RESOLVED');

        return app(ExecuteWorkflowTransition::class)->handle($ticket, $resolve->id, $requester, ['resolution_id' => $this->resolution()->id, 'notes' => 'Selesai'])->approval;
    }

    public function test_my_approvals_lists_only_current_eligible_requests(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $outsiderLeader = $this->member(Department::factory()->create(), leader: true);
        $request = $this->pendingApproval($programmer, [['approver_type' => 'department_leader']]);

        $this->actingAs($leader);
        Livewire::test('pages::approvals.mine')->assertSee($request->title)->assertSee($request->approvable->number);

        $this->actingAs($outsiderLeader);
        Livewire::test('pages::approvals.mine')->assertDontSee($request->approvable->number);

        $this->actingAs($request->requester);
        Livewire::test('pages::approvals.mine')->assertDontSee($request->approvable->number)
            ->set('section', 'requested')->assertSee($request->approvable->number);
    }

    public function test_approval_detail_does_not_leak_to_outsiders(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $request = $this->pendingApproval($programmer, [['approver_type' => 'department_leader']]);

        $this->actingAs($this->staff())->get(route('approvals.show', $request))->assertForbidden();
        $this->actingAs($leader)->get(route('approvals.show', $request))->assertOk()->assertSee('Approve');
        $this->actingAs($request->requester)->get(route('approvals.show', $request))->assertOk()->assertSee('Menunggu approver lain');
    }

    public function test_designated_approver_sees_only_the_request_summary(): void
    {
        $programmer = Department::factory()->create();
        $stranger = $this->staff();
        $request = $this->pendingApproval($programmer, [['approver_type' => 'specific_user', 'user_id' => $stranger->id]]);
        $ticket = $request->approvable;

        $this->assertFalse($ticket->isVisibleTo($stranger));
        $this->actingAs($stranger)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($stranger)->get(route('approvals.show', $request))
            ->assertOk()
            ->assertSee($ticket->number)
            ->assertSee('detail lengkap record tidak dapat Anda buka')
            ->assertDontSee(route('tickets.show', $ticket));
        $this->assertTrue($stranger->can('decide', $request));
    }

    public function test_permission_approver_without_record_access_is_not_eligible(): void
    {
        $programmer = Department::factory()->create();
        $request = $this->pendingApproval($programmer, [['approver_type' => 'permission', 'permission' => 'ticket.review']]);
        $holder = $this->userWithPermissions('ticket.view', 'ticket.review', 'approval.view', 'approval.decide');

        $this->actingAs($holder)->get(route('approvals.show', $request))->assertForbidden();
        $this->assertFalse($holder->can('decide', $request));
    }

    public function test_entity_page_shows_progress_and_dashboard_card(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $request = $this->pendingApproval($programmer, [['approver_type' => 'department_leader'], ['approver_type' => 'requester', 'allow_self_approval' => true]]);

        $this->actingAs($leader);
        Livewire::test('approvals.progress', ['subjectType' => 'ticket', 'subjectId' => $request->approvable_id])
            ->assertSee('Approval Pending')
            ->assertSee('0 dari 2 tahap selesai');

        $this->assertSame(1, Livewire::test('pages::dashboard')->assertSee('Pending My Approval')->instance()->pendingApprovals);
        $this->assertNotContains('resolve', array_column(Livewire::test('pages::tickets.show', ['ticket' => $request->approvable_id])->instance()->actions['more'], 'key'), 'Pending transition is not offered again');
        $this->assertNotSame('resolve', Livewire::test('pages::tickets.show', ['ticket' => $request->approvable_id])->instance()->actions['primary']['key'] ?? null);

        $this->actingAs($this->staff());
        Livewire::test('pages::dashboard')->assertDontSee('Pending My Approval');
    }

    public function test_decisions_through_the_approval_page(): void
    {
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $request = $this->pendingApproval($programmer, [['approver_type' => 'department_leader']]);

        $this->actingAs($leader);
        Livewire::test('pages::approvals.show', ['approvalRequest' => $request])
            ->call('open', 'reject')
            ->call('decide')
            ->assertHasErrors(['comment' => 'required'])
            ->call('open', 'approve')
            ->call('decide')
            ->assertHasNoErrors();

        $this->assertSame('RESOLVED', $request->approvable->refresh()->status->code);

        $this->actingAs($this->staff());
        Livewire::test('pages::approvals.mine')->assertSee('Tidak ada approval yang menunggu Anda.');
    }
}
