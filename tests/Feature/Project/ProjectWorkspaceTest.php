<?php

namespace Tests\Feature\Project;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class ProjectWorkspaceTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    public function test_overview_summarises_tasks_from_status_flags(): void
    {
        $manager = $this->staff();
        $project = $this->activateProject($this->createProject(['project_manager_id' => $manager->id]));
        $this->moveTask($this->createTask(['project_id' => $project->id, 'title' => 'Done task']), 'TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE');
        $this->moveTask($this->createTask(['project_id' => $project->id, 'title' => 'Working task']), 'TODO', 'IN_PROGRESS');
        $this->moveTask($this->createTask(['project_id' => $project->id, 'title' => 'Blocked task']), 'TODO', 'IN_PROGRESS', 'BLOCKED');
        $this->createTask(['project_id' => $project->id, 'title' => 'New task']);

        $this->actingAs($manager);
        $page = Livewire::test('pages::projects.show', ['project' => $project]);
        $summary = $page->instance()->taskSummary;

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['done']);
        $this->assertSame(1, $summary['in_progress']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame('Blocked task', $page->instance()->currentWork->first()->title, 'Blocked work comes first');
        $this->assertNotContains('Done task', $page->instance()->currentWork->pluck('title')->all());
        $page->assertSee('Department Progress');
    }

    public function test_current_work_shows_at_most_five_tasks(): void
    {
        $project = $this->createProject();

        foreach (range(1, 7) as $number) {
            $this->createTask(['project_id' => $project->id, 'title' => 'Task '.$number]);
        }

        $this->actingAs($this->superAdmin());

        $this->assertCount(5, Livewire::test('pages::projects.show', ['project' => $project])->instance()->currentWork);
    }

    public function test_open_reports_are_grouped_by_type_and_respect_ticket_visibility(): void
    {
        // A project member (not the PM, who sees every project report) only
        // counts the reports visible to them.
        $member = $this->staff();
        $project = $this->createProject();
        $this->addMember($project, $member);
        $programmer = Department::factory()->create();

        $this->createTicket(['project_id' => $project->id, 'title' => 'Payment journal error', 'current_department_id' => $programmer->id], $member);
        $this->createTicket(['project_id' => $project->id, 'title' => 'Database indexing', 'ticket_type_id' => $this->ticketType('MAINTENANCE')->id, 'current_department_id' => $programmer->id], $member);
        $this->createTicket(['project_id' => $project->id, 'title' => 'Secret report', 'current_department_id' => $programmer->id], $this->superAdmin());

        $this->actingAs($member);
        $page = Livewire::test('pages::projects.show', ['project' => $project]);
        $reports = $page->instance()->reportSummary;

        $this->assertSame(2, $reports['open']);
        $this->assertEqualsCanonicalizing(['Bug' => 1, 'Maintenance' => 1], array_column($reports['types'], 'count', 'name'));
        $page->assertSee('Payment journal error')->assertDontSee('Secret report');
    }

    public function test_project_has_four_primary_tabs_and_keeps_old_links_working(): void
    {
        $project = $this->createProject();
        $this->actingAs($this->superAdmin());

        $this->assertSame(['overview', 'tasks', 'reports', 'activity'], array_keys(Livewire::test('pages::projects.show', ['project' => $project])->instance()::TABS));

        Livewire::withQueryParams(['tab' => 'tickets'])->test('pages::projects.show', ['project' => $project])->assertSet('tab', 'reports');
        Livewire::withQueryParams(['tab' => 'team'])->test('pages::projects.show', ['project' => $project])->assertSet('tab', 'team')->assertSee('Add Member');
        Livewire::test('pages::projects.show', ['project' => $project])->set('tab', 'unknown')->assertSet('tab', 'overview');
    }

    public function test_header_offers_task_and_report_quick_actions_with_project_context(): void
    {
        $manager = $this->staff();
        $project = $this->createProject(['project_manager_id' => $manager->id]);

        $this->actingAs($manager)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(route('tasks.create', ['project' => $project->id]), false)
            ->assertSee(route('tickets.create', ['project' => $project->id]), false)
            ->assertSee('Project Manager')
            ->assertDontSee('Workflow');
    }

    public function test_overview_and_lists_use_a_bounded_number_of_queries(): void
    {
        $department = Department::factory()->create();
        $manager = $this->member($department);
        $project = $this->createProject(['project_manager_id' => $manager->id, 'lead_department_id' => $department->id]);

        foreach (range(1, 10) as $number) {
            $this->createTask(['title' => 'Task '.$number, 'project_id' => $project->id, 'department_id' => $department->id]);
            $this->createTicket(['project_id' => $project->id, 'current_department_id' => $department->id], $manager);
        }

        $this->actingAs($manager);
        $counts = [];

        foreach ([
            'overview' => route('projects.show', $project),
            'tasks' => route('projects.show', ['project' => $project, 'tab' => 'tasks']),
            'reports' => route('projects.show', ['project' => $project, 'tab' => 'reports']),
            'inbox' => route('tickets.inbox'),
            'my-tasks' => route('tasks.mine'),
        ] as $page => $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $counts[$page] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        foreach ($counts as $page => $queries) {
            $this->assertLessThan(70, $queries, $page.' must not issue a query per row');
        }
    }
}
