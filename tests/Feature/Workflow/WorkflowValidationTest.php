<?php

namespace Tests\Feature\Workflow;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Actions\CreateWorkflow;
use App\Domain\Workflow\Actions\ManageWorkflowDraft;
use App\Domain\Workflow\Actions\PublishWorkflowVersion;
use App\Domain\Workflow\Support\WorkflowVersionValidator;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class WorkflowValidationTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    private function emptyDraft(string $entity = 'task')
    {
        return app(CreateWorkflow::class)->handle(['name' => 'Simple', 'entity_type' => $entity], $this->superAdmin())->draftVersion()->firstOrFail();
    }

    public function test_exactly_one_initial_state(): void
    {
        $draft = $this->emptyDraft();
        $builder = app(ManageWorkflowDraft::class);
        $todo = $builder->saveState($draft, null, ['status_id' => $this->taskStatus('TODO')->id]);
        $done = $builder->saveState($draft, null, ['status_id' => $this->taskStatus('DONE')->id]);
        $builder->saveTransition($draft, null, ['from_state_id' => $todo->id, 'to_state_id' => $done->id, 'action_label' => 'Done', 'operation' => 'move', 'permissions' => ['task.complete']], $this->superAdmin());

        $this->assertContains('Workflow harus memiliki tepat satu state awal (saat ini 0).', app(WorkflowVersionValidator::class)->validate($draft)['errors']);

        $builder->saveState($draft, $todo, ['status_id' => $todo->status_id, 'is_initial' => true]);
        $builder->saveState($draft, $done, ['status_id' => $done->status_id, 'is_initial' => true]);
        $this->assertSame(1, WorkflowState::query()->where('workflow_version_id', $draft->id)->where('is_initial', true)->count(), 'Choosing a new initial releases the old one');

        $this->expectException(QueryException::class);
        WorkflowState::query()->whereKey($todo->id)->update(['is_initial' => true]); // database guard
    }

    public function test_status_scope_must_match_entity(): void
    {
        $draft = $this->emptyDraft('task');

        $this->expectException(BusinessRuleException::class);
        app(ManageWorkflowDraft::class)->saveState($draft, null, ['status_id' => $this->ticketStatus('IN_PROGRESS')->id]);
    }

    public function test_duplicate_and_cross_version_transitions_are_rejected(): void
    {
        $draft = $this->draftCopy('task');
        $existing = $this->transitionIn($draft, 'TODO', 'IN_PROGRESS');
        $builder = app(ManageWorkflowDraft::class);

        try {
            $builder->saveTransition($draft, null, ['from_state_id' => $existing->from_state_id, 'to_state_id' => $existing->to_state_id, 'action_label' => 'Again', 'operation' => 'move', 'permissions' => ['task.update']], $this->superAdmin());
            $this->fail('Duplicate path must be rejected.');
        } catch (BusinessRuleException) {
        }

        $foreign = $this->defaultWorkflow('task')->publishedVersion()->sole()->states()->firstOrFail();

        $this->expectExceptionMessage('harus berasal dari versi ini');
        $builder->saveTransition($draft, null, ['from_state_id' => $foreign->id, 'to_state_id' => $existing->to_state_id, 'action_label' => 'Hack', 'operation' => 'move', 'permissions' => ['task.update']], $this->superAdmin());
    }

    public function test_who_rule_and_operation_compatibility_are_required(): void
    {
        $draft = $this->draftCopy('ticket');
        $builder = app(ManageWorkflowDraft::class);
        $transition = $this->transitionIn($draft, 'IN_PROGRESS', 'IN_REVIEW');

        try {
            $this->editTransition($draft, $transition, ['roles' => [], 'permissions' => []]);
            $this->fail('A transition needs a "who" rule.');
        } catch (BusinessRuleException) {
        }

        $this->expectExceptionMessage('Resolve harus menuju status');
        $this->editTransition($draft, $transition, ['operation' => 'resolve']);
        unset($builder);
    }

    public function test_unreachable_and_dead_end_states_are_reported(): void
    {
        $draft = $this->draftCopy('task');
        $custom = Status::query()->forceCreate(['scope' => 'task', 'code' => 'QA_CHECK', 'name' => 'QA Check', 'color' => 'teal', 'sort_order' => 55]);
        app(ManageWorkflowDraft::class)->saveState($draft, null, ['status_id' => $custom->id]);

        $report = app(WorkflowVersionValidator::class)->validate($draft);

        $this->assertSame([], $report['errors']);
        $this->assertContains('State "QA Check" tidak dapat dicapai dari state awal.', $report['warnings']);
        $this->assertContains('State "QA Check" tidak memiliki aksi keluar (dead-end) padahal bukan state akhir.', $report['warnings']);
    }

    public function test_publish_requires_valid_draft_and_permission(): void
    {
        $draft = $this->emptyDraft();

        try {
            app(PublishWorkflowVersion::class)->handle($draft, $this->superAdmin());
            $this->fail('An empty draft must not publish.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('belum dapat dipublikasikan', $e->getMessage());
        }

        $viewer = User::factory()->withRole('Management')->create();
        $this->actingAs($viewer);
        Livewire::test('pages::admin.workflows.show', ['workflow' => $draft->workflow_id])->call('publish')->assertForbidden();
    }
}
