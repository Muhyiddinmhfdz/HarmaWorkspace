<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionRegistry;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminPages(): array
    {
        return [
            'users' => ['admin.users.index'],
            'user create' => ['admin.users.create'],
            'positions' => ['admin.positions.index'],
            'roles' => ['admin.roles.index'],
            'role create' => ['admin.roles.create'],
            'permissions' => ['admin.permissions.index'],
            'audit' => ['admin.audit.index'],
            'master data' => ['master-data.index'],
            'settings' => ['settings.index'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_staff_cannot_open_admin_pages(string $routeName): void
    {
        $this->actingAs($this->staff())
            ->get(route($routeName))
            ->assertForbidden();
    }

    public function test_staff_cannot_manage_roles_unless_permitted(): void
    {
        $this->actingAs($this->staff())->get(route('admin.roles.index'))->assertForbidden();

        $this->actingAs($this->userWithPermissions('role.view'))->get(route('admin.roles.index'))->assertOk();
        $this->actingAs($this->userWithPermissions('role.view'))->get(route('admin.roles.create'))->assertForbidden();
        $this->actingAs($this->userWithPermissions('role.view', 'role.manage'))->get(route('admin.roles.create'))->assertOk();
    }

    public function test_livewire_actions_are_authorized_even_when_called_directly(): void
    {
        $department = Department::factory()->create();

        $this->actingAs($this->userWithPermissions('department.view'));

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->assertForbidden();

        Livewire::test('pages::admin.departments.index')
            ->call('confirmStatus', $department->id)
            ->assertForbidden();

        $this->assertTrue($department->refresh()->is_active);
    }

    public function test_user_cannot_view_another_user_by_changing_the_url(): void
    {
        $staff = $this->staff();
        $other = $this->staff();

        $this->actingAs($staff)->get(route('admin.users.show', $other))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.users.edit', $other))->assertForbidden();
    }

    public function test_sidebar_only_lists_permitted_modules(): void
    {
        $this->actingAs($this->staff())
            ->get(route('dashboard'))
            ->assertSee(route('admin.departments.index'))
            ->assertDontSee(route('admin.users.index'))
            ->assertDontSee(route('admin.roles.index'))
            ->assertDontSee(route('admin.audit.index'));
    }

    public function test_super_admin_role_receives_every_registered_permission(): void
    {
        $admin = $this->superAdmin();

        foreach (PermissionRegistry::all() as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
            $this->assertTrue($admin->hasPermissionTo($permission), $permission.' should also be stored on the role');
        }
    }

    public function test_inactive_super_admin_gets_no_bypass(): void
    {
        $admin = User::factory()->superAdmin()->inactive()->create();
        $admin->roles()->first()->permissions()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($admin->fresh()->can('user.view'));
    }
}
