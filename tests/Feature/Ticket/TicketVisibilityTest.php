<?php

namespace Tests\Feature\Ticket;

use App\Domain\System\Search\GlobalSearch;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketVisibilityTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_who_can_see_a_ticket(): void
    {
        $programmer = Department::factory()->create();
        $requester = $this->staff();
        $pic = $this->member($programmer);
        $member = $this->member($programmer);
        $helper = $this->member($programmer);
        $outsider = $this->member(Department::factory()->create());
        $inactive = $this->member($programmer, user: User::factory()->withRole('Staff')->inactive()->create());
        $management = User::factory()->withRole('Management')->create();

        $ticket = $this->assignedTicket($programmer, $pic, ['requester_user_id' => $requester->id]);
        app(ManageTicketAssignments::class)->addCollaborator($ticket, $helper->id, $this->superAdmin());

        foreach ([$requester, $pic, $member, $helper, $management] as $user) {
            $this->assertTrue($ticket->isVisibleTo($user->fresh()), $user->name.' should see the ticket');
            $this->assertSame(1, Ticket::query()->visibleTo($user->fresh())->count());
        }

        foreach ([$outsider, $inactive] as $user) {
            $this->assertFalse($ticket->isVisibleTo($user->fresh()));
            $this->assertSame(0, Ticket::query()->visibleTo($user->fresh())->count());
        }
    }

    public function test_direct_url_respects_visibility(): void
    {
        $ticket = $this->createTicket();
        $outsider = $this->staff();

        $this->actingAs($outsider)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($outsider)->get(route('tickets.edit', $ticket))->assertForbidden();
        $this->actingAs($ticket->requester)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_project_visibility_does_not_grant_ticket_visibility_and_relation_is_masked(): void
    {
        $project = $this->createProject(['visibility' => 'company']);
        $projectViewer = $this->staff();
        $programmer = Department::factory()->create();
        $ticket = $this->createTicket(['project_id' => $project->id, 'current_department_id' => $programmer->id], $this->superAdmin());

        $this->assertTrue($project->isVisibleTo($projectViewer));
        $this->assertFalse($ticket->isVisibleTo($projectViewer), 'Seeing the project is not seeing its tickets');

        // A department member sees the ticket but not the restricted project.
        $privateProject = $this->createProject(['name' => 'Rahasia']);
        $member = $this->member($programmer);
        $private = $this->createTicket(['project_id' => $privateProject->id, 'current_department_id' => $programmer->id], $this->superAdmin());

        $this->actingAs($member)->get(route('tickets.show', $private))
            ->assertOk()
            ->assertSee('Project terbatas')
            ->assertDontSee('Rahasia');
    }

    public function test_project_manager_sees_tickets_of_their_project(): void
    {
        $pm = $this->staff();
        $project = $this->createProject(['project_manager_id' => $pm->id]);
        $ticket = $this->createTicket(['project_id' => $project->id], $this->superAdmin());

        $this->assertTrue($ticket->isVisibleTo($pm));
    }

    public function test_global_search_never_returns_hidden_tickets(): void
    {
        $visible = $this->createTicket(['title' => 'Printer kasir rusak']);
        $hidden = $this->createTicket(['title' => 'Printer gudang rusak']);

        $results = app(GlobalSearch::class)->search($visible->requester, 'Printer');
        $tickets = collect($results)->firstWhere('group', 'Reports');

        $this->assertNotNull($tickets);
        $this->assertSame(['Printer kasir rusak'], array_column($tickets['items'], 'title'));
        $this->assertStringContainsString($visible->number, $tickets['items'][0]['subtitle']);
        $this->assertNull(collect(app(GlobalSearch::class)->search($this->staff(), 'Printer'))->firstWhere('group', 'Reports'));
        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_dashboard_counts_only_visible_tickets(): void
    {
        $requester = $this->staff();
        $this->createTicket([], $requester);
        $this->createTicket(['due_date' => today()->addDay()->toDateString()], $requester);
        $this->createTicket([], $this->staff());

        $this->actingAs($requester);
        $cards = collect(Livewire::test('pages::dashboard')->instance()->workCards)->keyBy('label');

        $this->assertSame(2, $cards['My Open Reports']['value']);
    }

    public function test_leader_dashboard_shows_inbox_of_led_department(): void
    {
        $programmer = Department::factory()->create(['name' => 'Programmer']);
        $leader = $this->member($programmer, leader: true);
        $this->createTicket(['current_department_id' => $programmer->id]);
        $this->createTicket();

        $this->actingAs($leader);
        $inbox = Livewire::test('pages::dashboard')->assertSee('Department Inbox')->instance()->leaderInbox;

        $this->assertSame(1, $inbox['counts']['new']);
    }

    public function test_former_department_member_loses_access(): void
    {
        $programmer = Department::factory()->create();
        $member = $this->member($programmer);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);
        $this->assertTrue($ticket->isVisibleTo($member));

        $member->activeMemberships()->update(['ended_at' => today()]);

        $this->assertFalse($ticket->isVisibleTo($member->fresh()));
    }
}
