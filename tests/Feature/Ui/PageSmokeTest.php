<?php

namespace Tests\Feature\Ui;

use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Position;
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
     * @return array<string, array{0: string}>
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
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_for_super_admin(string $routeName): void
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

        $parameters = match ($routeName) {
            'admin.users.show', 'admin.users.edit' => ['user' => $member],
            'admin.departments.show' => ['department' => $department],
            'admin.roles.edit' => ['role' => Role::findByName('Staff')],
            'clients.show' => ['client' => $client],
            default => [],
        };

        $this->actingAs($admin)
            ->get(route($routeName, $parameters))
            ->assertOk();
    }
}
