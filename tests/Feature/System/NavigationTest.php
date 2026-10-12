<?php

namespace Tests\Feature\System;

use App\Domain\System\Navigation\Navigation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    /**
     * @return array<string, list<string>>
     */
    private function menu(User $user): array
    {
        return collect(app(Navigation::class)->for($user))
            ->mapWithKeys(fn (array $group): array => [$group['heading'] ?? '' => array_column($group['items'], 'label')])
            ->all();
    }

    public function test_staff_sees_operational_menus_only(): void
    {
        $menu = $this->menu($this->member(Department::factory()->create()));

        $this->assertSame(['Dashboard'], $menu['']);
        $this->assertSame(['Projects'], $menu['Projects']);
        $this->assertSame(['My Tasks', 'My Reports', 'My Approvals'], $menu['My Work']);
        $this->assertSame(['Reports', 'Department Inbox'], $menu['Reports']);
        $this->assertArrayNotHasKey('Administration', $menu, 'No empty or read-only administration section for staff');
    }

    public function test_super_admin_sees_administration(): void
    {
        $menu = $this->menu($this->superAdmin());

        $this->assertContains('All Tasks', $menu['Projects']);
        $this->assertSame(
            ['Members', 'Departments', 'Clients', 'Master Data', 'Workflows', 'Approval Templates', 'Role & Permission', 'System Settings', 'Audit Log'],
            $menu['Administration'],
        );
    }

    public function test_department_inbox_appears_only_where_relevant(): void
    {
        $this->assertNotContains('Department Inbox', $this->menu($this->staff())['Reports'], 'Staff without a department has no work queue');
        $this->assertContains('Department Inbox', $this->menu($this->member(Department::factory()->create(), leader: true))['Reports']);
        $this->assertContains('Department Inbox', $this->menu($this->userWithPermissions('ticket.view', 'ticket.manage-inbox'))['Reports']);
    }

    public function test_hidden_menus_stay_protected_by_the_backend(): void
    {
        $staff = $this->staff();

        foreach (['admin.workflows.index', 'admin.approval-templates.index', 'master-data.index', 'settings.index', 'admin.audit.index'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }

        $this->actingAs($this->userWithPermissions('dashboard.view'))->get(route('tickets.inbox'))->assertForbidden();
    }

    public function test_global_create_offers_only_everyday_actions(): void
    {
        $this->actingAs($this->staff())
            ->get(route('dashboard'))
            ->assertSee('New Task')
            ->assertSee('New Report')
            ->assertDontSee('New Project')
            ->assertDontSee('New Member')
            ->assertDontSee('New Department');

        $this->actingAs($this->superAdmin())
            ->get(route('dashboard'))
            ->assertSee('New Project')
            ->assertDontSee('New Member');
    }
}
