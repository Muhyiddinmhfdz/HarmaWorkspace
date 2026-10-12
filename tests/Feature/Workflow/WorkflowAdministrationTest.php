<?php

namespace Tests\Feature\Workflow;

use App\Domain\MasterData\Actions\ChangeMasterStatus;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\ApprovalTemplate;
use App\Models\Status;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class WorkflowAdministrationTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    public function test_staff_and_management_cannot_edit_workflows(): void
    {
        $this->actingAs($this->staff())->get(route('admin.workflows.index'))->assertForbidden();
        $this->actingAs($this->staff())->get(route('admin.approval-templates.index'))->assertForbidden();

        $management = User::factory()->withRole('Management')->create();
        $this->actingAs($management)->get(route('admin.workflows.index'))->assertOk()->assertDontSee('New Workflow');
        Livewire::test('pages::admin.workflows.index')->call('create')->assertForbidden();
    }

    public function test_admin_builds_and_publishes_a_simple_workflow_from_the_ui(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.workflows.index')
            ->call('create')
            ->set('name', 'Internal Task')
            ->set('entity_type', 'task')
            ->set('copy_from_workflow_id', '')
            ->call('save')
            ->assertHasNoErrors();

        $workflow = Workflow::query()->where('name', 'Internal Task')->sole();
        $page = Livewire::test('pages::admin.workflows.show', ['workflow' => $workflow]);

        foreach (['TODO' => true, 'IN_PROGRESS' => false, 'DONE' => false] as $code => $initial) {
            $page->call('openState')->set('stateStatusId', (string) $this->taskStatus($code)->id)->set('stateInitial', $initial)->call('saveState')->assertHasNoErrors();
        }

        $states = WorkflowState::query()->where('workflow_version_id', $workflow->draftVersion()->value('id'))->pluck('id', 'code');

        foreach ([['TODO', 'IN_PROGRESS', 'Start Work', ['main_pic']], ['IN_PROGRESS', 'DONE', 'Complete', ['department_leader']]] as [$from, $to, $label, $roles]) {
            $page->call('openTransition')
                ->set('transition.from_state_id', (string) $states[$from])
                ->set('transition.to_state_id', (string) $states[$to])
                ->set('transition.action_label', $label)
                ->set('transition.roles', $roles)
                ->call('saveTransition')
                ->assertHasNoErrors();
        }

        $this->assertSame([], $page->instance()->report['errors']);
        $page->call('publish')->assertDispatched('notify', type: 'success');

        $this->assertNotNull($workflow->publishedVersion()->first());
        $page->call('makeDefault');
        $this->assertTrue($workflow->fresh()->is_default);

        $task = $this->createTask();
        $this->assertSame('TODO', $task->status->code, 'New tasks start in the new initial state');
    }

    public function test_approval_template_is_built_from_the_ui(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.approval-templates.index')
            ->call('create')
            ->set('name', 'QA then TS')
            ->set('entity_type', 'ticket')
            ->call('save')
            ->assertHasNoErrors();

        $template = ApprovalTemplate::query()->where('name', 'QA then TS')->sole();
        $approver = $this->staff();

        Livewire::test('pages::admin.approval-templates.show', ['approvalTemplate' => $template])
            ->call('openStep')
            ->set('step.name', 'QA Review')
            ->set('step.approver_type', 'specific_user')
            ->set('step.user_id', $approver->id)
            ->call('saveStep')
            ->assertHasNoErrors()
            ->call('openStep')
            ->set('step.name', 'TS Acceptance')
            ->set('step.approver_type', 'permission')
            ->set('step.permission', 'ticket.review')
            ->call('saveStep')
            ->assertHasNoErrors();

        $steps = $template->steps()->get();
        $this->assertSame([1, 2], $steps->pluck('step_order')->all());
        $this->assertSame(['user_id' => $approver->id], $steps[0]->approver_config);
        $this->assertFalse($steps[0]->allow_self_approval, 'Self approval is off by default');
    }

    public function test_status_used_by_a_published_workflow_cannot_be_deactivated(): void
    {
        $inUse = $this->taskStatus('IN_REVIEW');

        try {
            app(ChangeMasterStatus::class)->handle($inUse, false);
            $this->fail('Status in a published workflow must not be deactivated.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('dipakai workflow', $e->getMessage());
        }

        $free = Status::query()->forceCreate(['scope' => 'task', 'code' => 'PARKED', 'name' => 'Parked', 'color' => 'zinc']);
        app(ChangeMasterStatus::class)->handle($free->fresh(), false);
        $this->assertFalse($free->fresh()->is_active);
        $this->assertTrue($inUse->fresh()->isCodeLocked(), 'Codes referenced by workflows are locked');
    }
}
