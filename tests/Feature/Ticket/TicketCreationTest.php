<?php

namespace Tests\Feature\Ticket;

use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketCreationTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_user_can_create_standalone_ticket_from_form(): void
    {
        $requester = $this->staff();
        $programmer = Department::factory()->create(['name' => 'Programmer']);

        $this->actingAs($requester);

        Livewire::test('pages::tickets.create')
            ->set('title', 'Error transaksi POS')
            ->set('ticket_type_id', (string) $this->ticketType()->id)
            ->set('ticket_category_id', (string) $this->ticketCategory()->id)
            ->set('current_department_id', $programmer->id)
            ->set('description', 'Saat pembayaran muncul error 500.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $ticket = Ticket::query()->with('status')->sole();
        $this->assertMatchesRegularExpression('/^TCK-\d{4}-0001$/', $ticket->number);
        $this->assertSame($requester->id, $ticket->requester_user_id);
        $this->assertSame($requester->id, $ticket->created_by);
        $this->assertSame($programmer->id, $ticket->current_department_id);
        $this->assertTrue($ticket->status->is_initial);
        $this->assertNull($ticket->accepted_at);
        $this->assertTrue($ticket->isStandalone());
    }

    public function test_numbers_are_unique_and_sequential(): void
    {
        $first = $this->createTicket();
        $second = $this->createTicket();

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringEndsWith('0002', $second->number);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->staff());

        // A single active category is preselected; clearing it must still fail.
        Livewire::test('pages::tickets.create')
            ->set('ticket_category_id', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'ticket_type_id' => 'required', 'ticket_category_id' => 'required', 'current_department_id' => 'required', 'description' => 'required']);
    }

    public function test_ticket_may_belong_to_project_and_task_with_client(): void
    {
        $project = $this->createProject(['client_id' => Client::factory()->create()->id]);
        $task = $this->createTask(['project_id' => $project->id]);
        $actor = $this->superAdmin();

        $ticket = $this->createTicket(['task_id' => $task->id, 'client_id' => $project->client_id], $actor);

        $this->assertSame($project->id, $ticket->project_id, 'Project follows the task');
        $this->assertSame($task->id, $ticket->task_id);
        $this->assertSame($project->client_id, $ticket->client_id);
    }

    public function test_task_of_another_project_is_rejected(): void
    {
        $projectA = $this->createProject();
        $projectB = $this->createProject(['name' => 'HRIS']);
        $task = $this->createTask(['project_id' => $projectB->id]);

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['project_id' => $projectA->id, 'task_id' => $task->id], $this->superAdmin());
    }

    public function test_subcategory_must_belong_to_category(): void
    {
        $other = $this->ticketCategory('INFRA');
        $vpn = TicketSubcategory::query()->create(['code' => 'VPN', 'name' => 'VPN', 'ticket_category_id' => $other->id]);

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['ticket_subcategory_id' => $vpn->id]);
    }

    public function test_inactive_department_is_rejected(): void
    {
        $inactive = Department::factory()->create(['is_active' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['current_department_id' => $inactive->id]);
    }

    public function test_user_cannot_link_project_or_task_they_cannot_see(): void
    {
        $project = $this->createProject();
        $task = $this->createTask(['project_id' => $project->id]);
        $outsider = $this->staff();

        try {
            $this->createTicket(['project_id' => $project->id], $outsider);
            $this->fail('Invisible project must be rejected.');
        } catch (BusinessRuleException) {
        }

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['task_id' => $task->id], $outsider);
    }

    public function test_archived_or_finished_project_takes_no_new_tickets(): void
    {
        $project = $this->createProject();
        app(ChangeProjectStatus::class)->handle($project, $this->projectStatus('CANCELLED')->id, $this->superAdmin());

        $this->expectException(BusinessRuleException::class);
        $this->createTicket(['project_id' => $project->id], $this->superAdmin());
    }

    public function test_sensitive_fields_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Ticket(['title' => 'x', 'closed_at' => now(), 'accepted_by' => 1, 'resolved_at' => now(), 'created_by' => 1]);
    }

    public function test_create_page_prefills_project_and_task(): void
    {
        $project = $this->createProject();
        $task = $this->createTask(['project_id' => $project->id]);

        $this->actingAs($this->superAdmin());

        Livewire::withQueryParams(['task' => $task->id])
            ->test('pages::tickets.create')
            ->assertSet('task_id', (string) $task->id)
            ->assertSet('project_id', (string) $project->id);
    }
}
