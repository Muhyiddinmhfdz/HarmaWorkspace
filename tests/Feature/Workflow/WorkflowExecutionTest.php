<?php

namespace Tests\Feature\Workflow;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\AddTicketAttachment;
use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Models\Department;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class WorkflowExecutionTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    private function engine(): ExecuteWorkflowTransition
    {
        return app(ExecuteWorkflowTransition::class);
    }

    public function test_valid_transition_runs_domain_side_effects_and_records_history(): void
    {
        $pic = $this->staff();
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]), 'TODO');
        $start = $this->transitionIn($task->workflowVersion()->sole(), 'TODO', 'IN_PROGRESS');

        $this->engine()->handle($task, $start->id, $pic);

        $task->refresh();
        $this->assertSame('IN_PROGRESS', $task->status->code);
        $this->assertNotNull($task->actual_start, 'Domain side effect preserved');
        $history = WorkflowTransitionHistory::query()->where('subject_type', 'task')->where('subject_id', $task->id)->latest('id')->first();
        $this->assertSame($start->id, $history->workflow_transition_id);
        $this->assertSame($pic->id, $history->actor_id);
    }

    public function test_transition_not_from_current_status_is_rejected_as_stale(): void
    {
        $task = $this->createTask();
        $start = $this->transitionIn($task->workflowVersion()->sole(), 'TODO', 'IN_PROGRESS'); // task is in Backlog

        $this->expectExceptionMessage('Status sudah berubah. Muat ulang data terbaru.');
        $this->engine()->handle($task, $start->id, $this->superAdmin());
    }

    public function test_stale_screen_is_rejected_after_someone_else_moved_the_record(): void
    {
        $pic = $this->staff();
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]), 'TODO');

        $this->actingAs($pic);
        $page = Livewire::test('pages::tasks.show', ['task' => $task]);
        $start = $this->transitionIn($task->workflowVersion()->sole(), 'TODO', 'IN_PROGRESS');

        $this->moveTask($task, 'IN_PROGRESS'); // someone else, meanwhile

        $page->call('startTransition', $start->id)->assertDispatched('notify', type: 'error', message: 'Status sudah berubah. Muat ulang data terbaru.');
    }

    public function test_unauthorized_actor_and_invisible_record_are_rejected(): void
    {
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $this->staff()->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW');
        $done = $this->transitionIn($task->workflowVersion()->sole(), 'IN_REVIEW', 'DONE');
        $pic = $task->picAssignment()->sole()->user;

        try {
            $this->engine()->handle($task, $done->id, $pic); // PIC cannot approve own work
            $this->fail('Context role rule must apply.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $this->engine()->handle($task, $done->id, $this->staff()); // cannot even see it
    }

    public function test_permission_rule_grants_the_transition(): void
    {
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $this->staff()->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW');
        $done = $this->transitionIn($task->workflowVersion()->sole(), 'IN_REVIEW', 'DONE');
        $completer = $this->userWithPermissions('task.view-all', 'task.complete');

        $this->engine()->handle($task, $done->id, $completer);

        $this->assertSame('DONE', $task->refresh()->status->code);
    }

    public function test_required_comment_and_attachment_are_enforced(): void
    {
        $draft = $this->draftCopy('ticket');
        $start = $this->transitionIn($draft, 'ASSIGNED', 'IN_PROGRESS');
        $this->editTransition($draft, $start, ['requirements' => ['comment_required', 'attachment_required']]);
        $this->publish($draft, makeDefault: true);

        $department = Department::factory()->create();
        $pic = $this->member($department);
        $ticket = $this->assignedTicket($department, $pic);
        $transition = $this->transitionIn($ticket->workflowVersion()->sole(), 'ASSIGNED', 'IN_PROGRESS');

        try {
            $this->engine()->handle($ticket, $transition->id, $pic, []);
            $this->fail('Comment required.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Komentar wajib', $e->getMessage());
        }

        try {
            $this->engine()->handle($ticket, $transition->id, $pic, ['comment' => 'Mulai']);
            $this->fail('Attachment required.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('lampiran', $e->getMessage());
        }

        app(AddTicketAttachment::class)->store($ticket, UploadedFile::fake()->create('log.txt', 1, 'text/plain'), $pic);
        $this->engine()->handle($ticket->refresh(), $transition->id, $pic, ['comment' => 'Mulai']);

        $this->assertSame('IN_PROGRESS', $ticket->refresh()->status->code);
    }

    public function test_cross_workflow_and_cross_entity_transition_ids_are_rejected(): void
    {
        $task = $this->moveTask($this->createTask(), 'TODO');
        $ticket = $this->createTicket();
        $otherVersion = $this->publish($this->draftCopy('task', 'Other Task Workflow'));
        $foreign = $this->transitionIn($otherVersion, 'TODO', 'IN_PROGRESS');
        $ticketTransition = $this->transitionIn($ticket->workflowVersion()->sole(), 'WAITING_ASSIGNMENT', 'CANCELLED');

        foreach ([$foreign->id, $ticketTransition->id] as $transitionId) {
            try {
                $this->engine()->handle($task, $transitionId, $this->superAdmin());
                $this->fail('Foreign transition id must be rejected.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('Aksi ini tidak berlaku untuk proses record ini.', $e->getMessage());
            }
        }

        $this->assertSame('TODO', $task->refresh()->status->code);
    }

    public function test_transition_history_is_immutable(): void
    {
        $this->moveTask($this->createTask(), 'TODO');
        $history = WorkflowTransitionHistory::query()->latest('id')->firstOrFail();

        $this->expectException(LogicException::class);
        $history->delete();
    }
}
