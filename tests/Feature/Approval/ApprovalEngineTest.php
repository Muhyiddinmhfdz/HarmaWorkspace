<?php

namespace Tests\Feature\Approval;

use App\Domain\Approval\Actions\DecideApproval;
use App\Domain\Approval\Actions\ManageApprovalTemplates;
use App\Domain\Approval\Support\ApproverResolver;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Enums\ApprovalDecisionType;
use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalStepStatus;
use App\Models\Activity;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    private Department $programmer;

    private Department $support;

    private User $pic;

    private User $requester;

    private User $devLead;

    private User $tsLead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmer = Department::factory()->create(['name' => 'Programmer']);
        $this->support = Department::factory()->create(['name' => 'Technical Support']);
        $this->pic = $this->member($this->programmer);
        $this->devLead = $this->member($this->programmer, leader: true);
        $this->tsLead = $this->member($this->support, leader: true);
        $this->requester = $this->staff();
    }

    /**
     * Ticket in review on a workflow whose Resolve needs DEV lead then TS lead.
     */
    private function reviewTicketWithApproval(?array $steps = null): Ticket
    {
        $template = $this->approvalTemplate($steps ?? [
            ['approver_type' => 'department_leader'],
            ['approver_type' => 'department_leader', 'department_id' => $this->support->id],
        ], 'ticket');
        $this->ticketResolveWithApproval($template);

        return $this->ticketInReview($this->programmer, $this->pic, ['requester_user_id' => $this->requester->id]);
    }

    private function requestResolve(Ticket $ticket, ?User $actor = null): ApprovalRequest
    {
        $resolve = $this->transitionIn($ticket->workflowVersion()->sole(), 'IN_REVIEW', 'RESOLVED');
        $outcome = app(ExecuteWorkflowTransition::class)->handle($ticket, $resolve->id, $actor ?? $this->requester, ['resolution_id' => $this->resolution()->id, 'notes' => 'Sudah diperbaiki']);

        $this->assertTrue($outcome->awaitsApproval());

        return $outcome->approval;
    }

    private function decide(ApprovalRequest $request, User $user, ApprovalDecisionType $type = ApprovalDecisionType::Approve, ?string $comment = null): string
    {
        return app(DecideApproval::class)->handle($request->refresh(), $type, $user->fresh(), $comment);
    }

    public function test_request_snapshots_steps_and_keeps_record_unchanged(): void
    {
        $ticket = $this->reviewTicketWithApproval();
        $request = $this->requestResolve($ticket);

        $this->assertSame('IN_REVIEW', $ticket->refresh()->status->code, 'No status change before approval');
        $this->assertSame(ApprovalRequestStatus::Pending, $request->status);
        $this->assertSame([ApprovalStepStatus::Pending, ApprovalStepStatus::Waiting], $request->steps()->pluck('status')->all());
        $this->assertTrue(Activity::query()->where('event', 'approval.requested')->where('subject_type', 'ticket')->exists());

        // Editing the template later does not touch the running request.
        $template = $request->template;
        app(ManageApprovalTemplates::class)->saveStep($template, $template->steps()->first(), ['name' => 'Renamed', 'approver_type' => 'requester']);
        $this->assertSame('Step 1', $request->steps()->first()->name);
        $this->assertSame('department_leader', $request->steps()->first()->approver_type->value);
    }

    public function test_sequential_approval_executes_the_transition(): void
    {
        $ticket = $this->reviewTicketWithApproval();
        $request = $this->requestResolve($ticket);

        try {
            $this->decide($request, $this->tsLead);
            $this->fail('Step 2 approver cannot act on step 1.');
        } catch (AuthorizationException) {
        }

        $this->decide($request, $this->devLead, comment: 'Kode sudah direview');
        $this->assertSame('IN_REVIEW', $ticket->refresh()->status->code);
        $this->assertSame(2, $request->refresh()->current_step_order);

        $message = $this->decide($request, $this->tsLead);

        $ticket->refresh();
        $this->assertStringContainsString('telah dijalankan', $message);
        $this->assertSame('RESOLVED', $ticket->status->code);
        $this->assertSame($this->requester->id, $ticket->resolved_by, 'Executed on behalf of the requester');
        $this->assertSame(ApprovalRequestStatus::Approved, $request->refresh()->status);
        $history = WorkflowTransitionHistory::query()->where('subject_type', 'ticket')->where('subject_id', $ticket->id)->latest('id')->first();
        $this->assertSame($request->id, $history->metadata['approval_request_id']);
        $this->assertSame(2, Activity::query()->where('event', 'approval.approved')->count());
    }

    public function test_self_approval_is_blocked_by_default_and_allowed_when_configured(): void
    {
        $ticket = $this->reviewTicketWithApproval([['approver_type' => 'requester']]);
        $request = $this->requestResolve($ticket);

        try {
            $this->decide($request, $this->requester);
            $this->fail('Self approval must be blocked.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Anda tidak dapat menyetujui permintaan Anda sendiri.', $e->getMessage());
        }

        app(ManageApprovalTemplates::class)->saveStep($request->template, $request->template->steps()->first(), ['name' => 'Self', 'approver_type' => 'requester', 'allow_self_approval' => true]);
        $other = $this->ticketInReview($this->programmer, $this->pic, ['requester_user_id' => $this->requester->id]);
        $selfRequest = $this->requestResolve($other);

        $this->decide($selfRequest, $this->requester);
        $this->assertSame('RESOLVED', $other->refresh()->status->code);
    }

    public function test_reject_and_revision_require_a_comment_and_leave_the_record(): void
    {
        $ticket = $this->reviewTicketWithApproval();
        $request = $this->requestResolve($ticket);

        foreach ([ApprovalDecisionType::Reject, ApprovalDecisionType::Revision] as $type) {
            try {
                $this->decide($request, $this->devLead, $type, '  ');
                $this->fail('Comment required.');
            } catch (BusinessRuleException) {
            }
        }

        $this->decide($request, $this->devLead, ApprovalDecisionType::Revision, 'Validasi error case belum lengkap.');

        $this->assertSame(ApprovalRequestStatus::RevisionRequested, $request->refresh()->status);
        $this->assertSame('IN_REVIEW', $ticket->refresh()->status->code);
        $this->assertSame([ApprovalStepStatus::RevisionRequested, ApprovalStepStatus::Skipped], $request->steps()->pluck('status')->all());

        // A new request is possible after revision; rejecting ends it.
        $second = $this->requestResolve($ticket);
        $this->decide($second, $this->devLead, ApprovalDecisionType::Reject, 'Output report belum sesuai.');
        $this->assertSame(ApprovalRequestStatus::Rejected, $second->refresh()->status);
        $this->assertSame('IN_REVIEW', $ticket->refresh()->status->code);
    }

    public function test_duplicate_pending_request_is_prevented(): void
    {
        $ticket = $this->reviewTicketWithApproval();
        $this->requestResolve($ticket);

        $this->expectExceptionMessage('Masih ada permintaan approval yang berjalan');
        $this->requestResolve($ticket);
    }

    public function test_stale_approval_is_cancelled_instead_of_forcing_the_transition(): void
    {
        $ticket = $this->reviewTicketWithApproval([['approver_type' => 'department_leader']]);
        $request = $this->requestResolve($ticket);

        app(ChangeTicketStatus::class)->requestRevision($ticket->refresh(), 'Ada bug baru', $this->devLead);

        $message = $this->decide($request, $this->devLead);

        $this->assertStringContainsString('tidak dijalankan', $message);
        $this->assertSame(ApprovalRequestStatus::Cancelled, $request->refresh()->status);
        $this->assertStringContainsString('Status record sudah berubah', $request->outcome_note);
        $this->assertSame('REVISION', $ticket->refresh()->status->code);
        $this->assertSame(1, ApprovalDecision::query()->count(), 'The decision is kept as history');
    }

    public function test_domain_prerequisites_are_checked_before_requesting_approval(): void
    {
        $template = $this->approvalTemplate([['approver_type' => 'project_owner']], 'project');
        $draft = $this->draftCopy('project');
        $this->editTransition($draft, $this->transitionIn($draft, 'ACTIVE', 'COMPLETED'), ['approval_template_id' => $template->id]);
        $this->publish($draft, makeDefault: true);

        $project = $this->activateProject($this->createProject(['start_date' => today()->subMonth()->toDateString(), 'owner_id' => $this->staff()->id]));
        $this->createTask(['project_id' => $project->id]);
        $complete = $this->transitionIn($project->workflowVersion()->sole(), 'ACTIVE', 'COMPLETED');

        try {
            app(ExecuteWorkflowTransition::class)->handle($project, $complete->id, $this->superAdmin());
            $this->fail('Approval must not be requested while tasks are active.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Task aktif', $e->getMessage());
        }

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_minimum_approvals_on_a_step(): void
    {
        $secondLead = $this->member($this->programmer, leader: true);
        $ticket = $this->reviewTicketWithApproval([['approver_type' => 'department_leader', 'minimum_approvals' => 2]]);
        $request = $this->requestResolve($ticket);

        $this->assertStringContainsString('Menunggu approver lain', $this->decide($request, $this->devLead));
        $this->assertSame('IN_REVIEW', $ticket->refresh()->status->code);

        try {
            $this->decide($request, $this->devLead);
            $this->fail('The same approver cannot decide twice.');
        } catch (AuthorizationException) {
        }

        $this->decide($request, $secondLead);
        $this->assertSame('RESOLVED', $ticket->refresh()->status->code);
    }

    public function test_inactive_specific_approver_is_unavailable_and_cannot_decide(): void
    {
        $approver = $this->staff();
        $ticket = $this->reviewTicketWithApproval([['approver_type' => 'specific_user', 'user_id' => $approver->id]]);
        $approver->forceFill(['is_active' => false])->save();

        $request = $this->requestResolve($ticket);
        $step = $request->steps()->first();

        $this->assertTrue(app(ApproverResolver::class)->candidates($step, $request, $ticket)->isEmpty(), 'Approver unavailable');

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $approver);
    }

    public function test_decisions_are_immutable(): void
    {
        $ticket = $this->reviewTicketWithApproval([['approver_type' => 'department_leader']]);
        $this->decide($this->requestResolve($ticket), $this->devLead);

        $this->expectException(LogicException::class);
        ApprovalDecision::query()->firstOrFail()->forceFill(['comment' => 'changed'])->save();
    }

    public function test_transition_requiring_approval_cannot_be_executed_directly(): void
    {
        $this->reviewTicketWithApproval();
        $ticket = $this->ticketInReview($this->programmer, $this->pic);

        $this->expectExceptionMessage('membutuhkan approval');
        app(ChangeTicketStatus::class)->resolve($ticket, $this->resolution()->id, 'Selesai', $this->devLead);
    }
}
