<?php

namespace Tests\Feature\Ticket;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

/**
 * Phase 6: tickets are presented as "Reports"; the backend stays Ticket.
 */
class ReportUxTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_operational_pages_use_report_terminology(): void
    {
        $requester = $this->staff();
        $ticket = $this->createTicket([], $requester);
        $this->actingAs($requester);

        $this->get(route('tickets.create'))->assertOk()->assertSee('New Report')->assertSee('What are you reporting?');
        $this->get(route('tickets.index'))->assertOk()->assertSee('Reports');
        $this->get(route('tickets.mine'))->assertOk()->assertSee('My Reports')->assertSee('Reported by Me');
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('Reported by')->assertDontSee('Workflow');
    }

    public function test_report_from_project_prefills_the_project(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        $this->addMember($project, $member);
        $this->actingAs($member);

        Livewire::withQueryParams(['project' => $project->id])
            ->test('pages::tickets.create')
            ->assertSet('project_id', (string) $project->id)
            ->set('ticket_type_id', (string) $this->ticketType()->id)
            ->set('ticket_category_id', (string) $this->ticketCategory()->id)
            ->set('title', 'Payment journal error')
            ->set('current_department_id', Department::factory()->create()->id)
            ->set('description', 'Jurnal pembayaran tidak terbentuk.')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Payment journal error')->firstOrFail();
        $this->assertSame($project->id, $ticket->project_id);
        $this->assertNull($ticket->task_id);
    }

    public function test_report_from_task_prefills_project_and_task(): void
    {
        $member = $this->staff();
        $project = $this->createProject();
        $this->addMember($project, $member);
        $task = $this->createTask(['project_id' => $project->id]);
        $this->actingAs($member);

        Livewire::withQueryParams(['task' => $task->id])
            ->test('pages::tickets.create')
            ->assertSet('project_id', (string) $project->id)
            ->assertSet('task_id', (string) $task->id)
            ->set('ticket_type_id', (string) $this->ticketType()->id)
            ->set('ticket_category_id', (string) $this->ticketCategory()->id)
            ->set('title', 'API timeout')
            ->set('current_department_id', Department::factory()->create()->id)
            ->set('description', 'API payment timeout di staging.')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'API timeout')->firstOrFail();
        $this->assertSame([$project->id, $task->id], [$ticket->project_id, $ticket->task_id]);
    }

    public function test_unrelated_task_cannot_be_injected_into_a_project_report(): void
    {
        $projectA = $this->createProject();
        $projectB = $this->createProject(['name' => 'HRIS']);
        $foreignTask = $this->createTask(['project_id' => $projectB->id]);
        $this->actingAs($this->superAdmin());

        Livewire::withQueryParams(['project' => $projectA->id])
            ->test('pages::tickets.create')
            ->set('ticket_type_id', (string) $this->ticketType()->id)
            ->set('ticket_category_id', (string) $this->ticketCategory()->id)
            ->set('title', 'Injected')
            ->set('current_department_id', Department::factory()->create()->id)
            ->set('description', 'Mencoba task dari project lain.')
            ->set('task_id', (string) $foreignTask->id)
            ->call('save')
            ->assertDispatched('notify', type: 'error');

        $this->assertFalse(Ticket::query()->where('title', 'Injected')->exists());

        Livewire::withQueryParams(['project' => $projectA->id])
            ->test('pages::tickets.create')
            ->set('project_id', (string) $projectB->id)
            ->assertForbidden();
    }

    public function test_report_cannot_start_from_a_task_the_user_cannot_see(): void
    {
        $task = $this->createTask(['project_id' => $this->createProject()->id]);

        $this->actingAs($this->staff());

        Livewire::withQueryParams(['task' => $task->id])->test('pages::tickets.create')->assertForbidden();
    }

    public function test_primary_report_action_still_runs_the_workflow(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $ticket = $this->assignedTicket($programmer, $pic);

        $this->actingAs($pic);
        $page = Livewire::test('pages::tickets.show', ['ticket' => $ticket]);
        $this->assertSame('start', $page->instance()->actions['primary']['key']);

        $page->call('perform', 'start');

        $this->assertTrue($ticket->refresh()->status->counts_as_started);
        $this->assertTrue(WorkflowTransitionHistory::query()->where('subject_type', 'ticket')->where('subject_id', $ticket->id)->whereNotNull('workflow_transition_id')->exists(), 'The button executed a workflow transition');
    }
}
