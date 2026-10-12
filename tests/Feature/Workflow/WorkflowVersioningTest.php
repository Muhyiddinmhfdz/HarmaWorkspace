<?php

namespace Tests\Feature\Workflow;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Actions\CreateDraftVersion;
use App\Domain\Workflow\Actions\DeleteDraftVersion;
use App\Domain\Workflow\Actions\ManageWorkflowDraft;
use App\Domain\Workflow\Actions\UpdateWorkflow;
use App\Enums\WorkflowVersionStatus;
use App\Models\Activity;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class WorkflowVersioningTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    public function test_new_version_does_not_change_running_records(): void
    {
        $workflow = $this->defaultWorkflow('task');
        $v1 = $workflow->publishedVersion()->sole();
        $taskA = $this->createTask(['title' => 'Task A']);

        // v2: Backlog can no longer be cancelled.
        $draft = app(CreateDraftVersion::class)->handle($workflow, $this->superAdmin());
        app(ManageWorkflowDraft::class)->removeTransition($draft, $this->transitionIn($draft, 'BACKLOG', 'CANCELLED'));
        $v2 = $this->publish($draft);

        $taskB = $this->createTask(['title' => 'Task B']);

        $this->assertSame($v1->id, $taskA->refresh()->workflow_version_id, 'Ticket A stays on v1');
        $this->assertSame($v2->id, $taskB->workflow_version_id, 'New records use v2');
        $this->assertSame(WorkflowVersionStatus::Archived, $v1->refresh()->status);
        $this->assertSame(2, $v2->version_number);

        $this->moveTask($taskA, 'CANCELLED'); // still allowed on v1

        $this->expectException(BusinessRuleException::class);
        $this->moveTask($taskB, 'CANCELLED');
    }

    public function test_published_version_is_immutable(): void
    {
        $published = $this->defaultWorkflow('ticket')->publishedVersion()->sole();
        $transition = $this->transitionIn($published, 'IN_PROGRESS', 'IN_REVIEW');

        $this->expectExceptionMessage('tidak dapat diubah');
        app(ManageWorkflowDraft::class)->removeTransition($published, $transition);
    }

    public function test_only_one_draft_and_unused_drafts_can_be_deleted(): void
    {
        $workflow = $this->defaultWorkflow('project');
        $draft = app(CreateDraftVersion::class)->handle($workflow, $this->superAdmin());

        try {
            app(CreateDraftVersion::class)->handle($workflow, $this->superAdmin());
            $this->fail('A second draft must be rejected.');
        } catch (BusinessRuleException) {
        }

        app(DeleteDraftVersion::class)->handle($draft);
        $this->assertNull(WorkflowVersion::query()->find($draft->id));

        $this->expectException(BusinessRuleException::class);
        app(DeleteDraftVersion::class)->handle($workflow->publishedVersion()->sole());
    }

    public function test_default_switch_affects_only_new_records_and_default_cannot_be_deactivated(): void
    {
        $original = $this->defaultWorkflow('ticket');
        $before = $this->createTicket();
        $custom = $this->publish($this->draftCopy('ticket', 'Incident Workflow'), makeDefault: true);

        $after = $this->createTicket();

        $this->assertSame($original->publishedVersion()->value('id'), $before->refresh()->workflow_version_id);
        $this->assertSame($custom->id, $after->workflow_version_id);
        $this->assertTrue(Activity::query()->where('event', 'workflow.default_changed')->exists());
        $this->assertTrue(Activity::query()->where('event', 'workflow.version_published')->exists());

        $this->expectException(BusinessRuleException::class);
        app(UpdateWorkflow::class)->setActive($custom->workflow()->sole(), false, $this->superAdmin());
    }

    public function test_record_may_start_on_a_chosen_usable_workflow(): void
    {
        $custom = $this->publish($this->draftCopy('ticket', 'Bug Workflow'));

        $ticket = $this->createTicket(['workflow_id' => $custom->workflow_id]);

        $this->assertSame($custom->id, $ticket->workflow_version_id);
    }
}
