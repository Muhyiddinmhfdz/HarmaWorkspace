<?php

namespace Tests\Feature\Approval;

use App\Domain\Approval\Actions\DecideApproval;
use App\Enums\ApprovalDecisionType;
use App\Models\ApprovalRequest;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class TaskApprovalTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    public function test_task_done_through_page_waits_for_department_lead_approval(): void
    {
        $template = $this->approvalTemplate([['approver_type' => 'department_leader']], 'task', 'Task Simple Review');
        $draft = $this->draftCopy('task', 'Task with Review Approval');
        $this->editTransition($draft, $this->transitionIn($draft, 'IN_REVIEW', 'DONE'), ['approval_template_id' => $template->id]);
        $this->publish($draft, makeDefault: true);

        $department = Department::factory()->create();
        $lead = $this->member($department, leader: true);
        $pm = $this->staff();
        $project = $this->createProject(['project_manager_id' => $pm->id, 'lead_department_id' => $department->id]);
        $pic = $this->staff();
        $this->addMember($project, $pic);
        $task = $this->moveTask($this->createTask(['project_id' => $project->id, 'department_id' => $department->id, 'pic_user_id' => $pic->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW');

        $this->actingAs($pic);
        $this->assertNotContains('approval', collect(Livewire::test('pages::tasks.show', ['task' => $task])->instance()->transitions)->pluck('kind')->all(), 'PIC is not offered Done');

        $this->actingAs($pm);
        $page = Livewire::test('pages::tasks.show', ['task' => $task]);
        $done = collect($page->instance()->transitions)->firstWhere('kind', 'approval');
        $page->call('startTransition', $done['id'])
            ->assertSet('workflowTransitionId', $done['id'])
            ->set('transitionInput.comment', 'Semua testcase lulus.')
            ->call('submitTransition')
            ->assertDispatched('notify', type: 'info');

        $this->assertSame('IN_REVIEW', $task->refresh()->status->code);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame('Semua testcase lulus.', $request->input['comment']);

        app(DecideApproval::class)->handle($request, ApprovalDecisionType::Approve, $lead);

        $task->refresh();
        $this->assertSame('DONE', $task->status->code);
        $this->assertSame(100, $task->progress_percentage, 'Domain side effects preserved');
    }
}
