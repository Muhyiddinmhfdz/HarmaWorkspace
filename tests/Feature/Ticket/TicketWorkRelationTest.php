<?php

namespace Tests\Feature\Ticket;

use App\Domain\Project\Actions\CompleteProject;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\LinkTicketWork;
use App\Domain\Ticket\Actions\ManageTicketRelations;
use App\Models\Activity;
use App\Models\Department;
use App\Models\TicketRelation;
use App\Models\TicketRelationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketWorkRelationTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_linking_rejects_unrelated_task_and_project(): void
    {
        $projectA = $this->createProject();
        $projectB = $this->createProject(['name' => 'HRIS']);
        $taskB = $this->createTask(['project_id' => $projectB->id]);
        $ticket = $this->createTicket([], $this->superAdmin());

        $this->expectException(BusinessRuleException::class);
        app(LinkTicketWork::class)->handle($ticket, $projectA->id, $taskB->id, $this->superAdmin());
    }

    public function test_linking_is_recorded_and_requires_access(): void
    {
        $project = $this->createProject();
        $ticket = $this->createTicket();

        app(LinkTicketWork::class)->handle($ticket, $project->id, null, $this->superAdmin());
        $this->assertSame($project->id, $ticket->refresh()->project_id);
        $this->assertTrue(Activity::query()->where('event', 'ticket.project_linked')->exists());

        $this->expectException(BusinessRuleException::class);
        app(LinkTicketWork::class)->handle($this->createTicket(), $project->id, null, $this->staff());
    }

    public function test_ticket_and_task_lifecycles_stay_independent(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $project = $this->createProject();
        $task = $this->moveTask($this->createTask(['project_id' => $project->id]), 'TODO', 'IN_PROGRESS');
        $ticket = $this->ticketInReview($programmer, $pic, ['task_id' => $task->id, 'requester_user_id' => $this->superAdmin()->id]);
        app(ChangeTicketStatus::class)->resolve($ticket, $this->resolution()->id, 'Selesai', $this->superAdmin());

        $this->assertSame('IN_PROGRESS', $task->refresh()->status->code, 'Resolving a ticket does not complete the task');

        $this->moveTask($task, 'IN_REVIEW', 'DONE');
        $this->assertSame('RESOLVED', $ticket->refresh()->status->code, 'Completing the task does not close the ticket');
    }

    public function test_project_with_active_ticket_cannot_complete(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $project = $this->activateProject($this->createProject(['start_date' => today()->subMonth()->toDateString()]));
        $active = $this->assignedTicket($programmer, $pic, ['project_id' => $project->id, 'requester_user_id' => $this->superAdmin()->id]);
        app(ChangeTicketStatus::class)->start($active, $pic);

        try {
            app(CompleteProject::class)->handle($project, $this->superAdmin());
            $this->fail('Active tickets must block completion.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('1 Ticket aktif: 1 Ticket masih In Progress', $e->getMessage());
        }

        $status = app(ChangeTicketStatus::class);
        $status->sendToReview($active->refresh(), $pic);
        $status->resolve($active->refresh(), $this->resolution()->id, 'Selesai', $this->superAdmin());

        $cancelled = $this->createTicket(['project_id' => $project->id], $this->superAdmin());
        $status->cancel($cancelled, 'Tidak diperlukan', $this->superAdmin());

        app(CompleteProject::class)->handle($project->refresh(), $this->superAdmin());
        $this->assertTrue($project->refresh()->status->counts_as_completed, 'Resolved and cancelled tickets do not block');
        $this->assertSame('RESOLVED', $active->refresh()->status->code, 'Project completion closes nothing automatically');
    }

    public function test_duplicate_resolution_links_primary_ticket(): void
    {
        $programmer = Department::factory()->create();
        $pic = $this->member($programmer);
        $primary = $this->createTicket(['current_department_id' => $programmer->id]);
        $duplicate = $this->ticketInReview($programmer, $pic);

        try {
            app(ChangeTicketStatus::class)->resolve($duplicate, $this->resolution('DUPLICATE')->id, 'Sama dengan ticket lain', $this->superAdmin());
            $this->fail('Duplicate requires the primary ticket.');
        } catch (BusinessRuleException) {
        }

        app(ChangeTicketStatus::class)->resolve($duplicate->refresh(), $this->resolution('DUPLICATE')->id, 'Sama dengan ticket lain', $this->superAdmin(), $primary->id);

        $relation = TicketRelation::query()->sole();
        $this->assertSame($duplicate->id, $relation->ticket_id);
        $this->assertSame($primary->id, $relation->related_ticket_id);
        $this->assertTrue($relation->type->is_duplicate);
    }

    public function test_ticket_relations_prevent_self_and_duplicates(): void
    {
        $a = $this->createTicket();
        $b = $this->createTicket();
        $type = TicketRelationType::query()->where('code', 'RELATED_TO')->sole();
        $relations = app(ManageTicketRelations::class);

        $relations->add($a, $b->id, $type->id, $this->superAdmin());

        foreach ([[$a, $a->id], [$a, $b->id], [$b, $a->id]] as [$ticket, $relatedId]) {
            try {
                $relations->add($ticket, $relatedId, $type->id, $this->superAdmin());
                $this->fail('Self or duplicate relation must be rejected.');
            } catch (BusinessRuleException) {
            }
        }

        $this->assertSame(1, TicketRelation::query()->count());
    }

    public function test_task_detail_lists_related_tickets_and_new_ticket_prefill(): void
    {
        $project = $this->createProject();
        $task = $this->createTask(['project_id' => $project->id]);
        $ticket = $this->createTicket(['task_id' => $task->id, 'title' => 'Error API Penjualan'], $this->superAdmin());

        $this->actingAs($this->superAdmin());
        Livewire::test('tickets.related', ['taskId' => $task->id])
            ->assertSee('Error API Penjualan')
            ->assertSee(route('tickets.create', ['task' => $task->id]));

        $this->assertTrue(Activity::query()->where('event', 'ticket.created')->where('properties->task_id', $task->id)->exists());
        $this->assertSame($ticket->task_id, $task->id);
    }

    public function test_project_tickets_tab_lists_project_tickets(): void
    {
        $project = $this->createProject();
        $this->createTicket(['project_id' => $project->id, 'title' => 'Ticket proyek POS'], $this->superAdmin());
        $this->createTicket(['title' => 'Ticket lain']);

        $this->actingAs($this->superAdmin());
        Livewire::test('tickets.table', ['mode' => 'project', 'projectId' => $project->id])
            ->assertSee('Ticket proyek POS')
            ->assertDontSee('Ticket lain');
    }
}
