<?php

namespace Tests\Feature\Approval;

use App\Domain\Approval\Support\ApproverResolver;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Enums\ApproverType;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkflows;
use Tests\TestCase;

class ApproverResolverTest extends TestCase
{
    use CreatesWorkflows, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $context
     */
    private function step(ApproverType $type, array $context = []): ApprovalStep
    {
        $step = new ApprovalStep;
        $step->forceFill(['approver_type' => $type, 'approver_context' => $context, 'allow_self_approval' => false, 'minimum_approvals' => 1]);

        return $step;
    }

    public function test_contextual_approver_types(): void
    {
        $resolver = app(ApproverResolver::class);
        $programmer = Department::factory()->create();
        $leader = $this->member($programmer, leader: true);
        $pm = $this->staff();
        $reviewerRole = ProjectRole::query()->firstOrCreate(['code' => 'REVIEWER'], ['name' => 'Reviewer']);
        $project = $this->createProject(['project_manager_id' => $pm->id, 'lead_department_id' => $programmer->id]);
        $reviewer = $this->staff();
        app(ManageProjectMembers::class)->add($project, $reviewer->id, $reviewerRole->id, null);
        $requester = $this->staff();
        $ticket = $this->createTicket(['current_department_id' => $programmer->id, 'project_id' => $project->id, 'requester_user_id' => $requester->id], $this->superAdmin());
        $someone = $this->staff();

        $this->assertTrue($resolver->matches($leader, $this->step(ApproverType::DepartmentLeader), $ticket));
        $this->assertFalse($resolver->matches($someone, $this->step(ApproverType::DepartmentLeader), $ticket));
        $this->assertTrue($resolver->matches($pm, $this->step(ApproverType::ProjectManager), $ticket));
        $this->assertTrue($resolver->matches($reviewer, $this->step(ApproverType::ProjectRole, ['project_role_id' => $reviewerRole->id]), $ticket));
        $this->assertFalse($resolver->matches($pm, $this->step(ApproverType::ProjectRole, ['project_role_id' => $reviewerRole->id]), $ticket));
        $this->assertTrue($resolver->matches($requester, $this->step(ApproverType::Requester), $ticket));
        $this->assertTrue($resolver->matches($someone, $this->step(ApproverType::SpecificUser, ['user_id' => $someone->id]), $ticket));
        $this->assertFalse($resolver->matches($leader, $this->step(ApproverType::SpecificUser, ['user_id' => $someone->id]), $ticket));
    }

    public function test_permission_approver_also_needs_to_see_the_record(): void
    {
        $resolver = app(ApproverResolver::class);
        $ticket = $this->createTicket();
        $holder = $this->userWithPermissions('ticket.review', 'ticket.view');
        $viewer = $this->userWithPermissions('ticket.review', 'ticket.view-all');
        $step = $this->step(ApproverType::Permission, ['permission' => 'ticket.review']);
        $request = new ApprovalRequest;
        $request->forceFill(['requested_by' => $ticket->requester_user_id]);

        $this->assertTrue($resolver->matches($holder, $step, $ticket), 'Holds the permission');
        $this->assertFalse($ticket->isVisibleTo($holder));
        $this->assertTrue($ticket->isVisibleTo($viewer));
        $this->assertTrue($resolver->matches($viewer, $step, $ticket));
        $this->assertNull($resolver->candidates($step, $request, $ticket), 'Open set');
        unset($request);
        $this->assertInstanceOf(User::class, $viewer);
    }
}
