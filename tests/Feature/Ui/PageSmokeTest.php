<?php

namespace Tests\Feature\Ui;

use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every Phase 1 page renders for a Super Admin with realistic data.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1?: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'profile' => ['profile.edit'],
            'users' => ['admin.users.index'],
            'user create' => ['admin.users.create'],
            'user show' => ['admin.users.show'],
            'user edit' => ['admin.users.edit'],
            'departments' => ['admin.departments.index'],
            'department show' => ['admin.departments.show'],
            'positions' => ['admin.positions.index'],
            'roles' => ['admin.roles.index'],
            'role create' => ['admin.roles.create'],
            'role edit' => ['admin.roles.edit'],
            'permissions' => ['admin.permissions.index'],
            'audit' => ['admin.audit.index'],
            'clients' => ['clients.index'],
            'client show' => ['clients.show'],
            'master data' => ['master-data.index'],
            'settings' => ['settings.index'],
            'projects' => ['projects.index'],
            'project create' => ['projects.create'],
            'project show' => ['projects.show'],
            'project edit' => ['projects.edit'],
            'project milestones tab' => ['projects.show', 'milestones'],
            'project team tab' => ['projects.show', 'team'],
            'project activity tab' => ['projects.show', 'activity'],
            'master project statuses' => ['master-data.manage', 'project-statuses'],
            'master project priorities' => ['master-data.manage', 'project-priorities'],
            'master project categories' => ['master-data.manage', 'project-categories'],
            'master project roles' => ['master-data.manage', 'project-roles'],
            'master project health' => ['master-data.manage', 'project-health'],
            'project tasks tab' => ['projects.show', 'tasks'],
            'tasks' => ['tasks.index'],
            'my tasks' => ['tasks.mine'],
            'task create' => ['tasks.create'],
            'task show' => ['tasks.show'],
            'task edit' => ['tasks.edit'],
            'master task statuses' => ['master-data.manage', 'task-statuses'],
            'master task types' => ['master-data.manage', 'task-types'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_for_super_admin(string $routeName, ?string $variant = null): void
    {
        $admin = $this->superAdmin();
        $department = Department::factory()->create();
        $position = Position::factory()->create();
        $member = User::factory()->withRole('Staff')->create();
        DepartmentMembership::factory()->primary()->leader()->create([
            'user_id' => $member->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
        ]);
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $project->milestones()->forceCreate(['name' => 'Go Live', 'planned_date' => today()->addWeek(), 'current_due_date' => today()->addWeek()]);
        $task = Task::factory()->create(['project_id' => $project->id, 'department_id' => $project->leadDepartment()->value('department_id'), 'current_end' => today()->addDays(3)]);

        $parameters = match ($routeName) {
            'admin.users.show', 'admin.users.edit' => ['user' => $member],
            'admin.departments.show' => ['department' => $department],
            'admin.roles.edit' => ['role' => Role::findByName('Staff')],
            'clients.show' => ['client' => $client],
            'projects.show' => ['project' => $project, 'tab' => $variant ?? 'overview'],
            'projects.edit' => ['project' => $project],
            'master-data.manage' => ['type' => $variant],
            'tasks.show', 'tasks.edit' => ['task' => $task],
            default => [],
        };

        $this->actingAs($admin)
            ->get(route($routeName, $parameters))
            ->assertOk();
    }
}
