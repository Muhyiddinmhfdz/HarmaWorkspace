<?php

namespace Tests\Feature\Access;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_role_with_permissions(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.roles.form')
            ->set('name', 'HR Admin')
            ->set('permissions', ['user.view', 'user.create'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::findByName('HR Admin');
        $this->assertEqualsCanonicalizing(['user.view', 'user.create'], $role->permissions->pluck('name')->all());
        $this->assertTrue(Activity::query()->where('event', 'role.created')->exists());
    }

    public function test_permission_change_is_audited_with_added_and_removed_permissions(): void
    {
        $role = Role::findByName('Staff');

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.roles.form', ['role' => $role])
            ->set('permissions', ['dashboard.view', 'client.view', 'client.create', 'project.view', 'task.view', 'task.create', 'ticket.view', 'ticket.create', 'approval.view', 'approval.decide'])
            ->call('save')
            ->assertHasNoErrors();

        $log = Activity::query()->where('event', 'role.updated')->firstOrFail();
        $this->assertSame(['client.create'], $log->properties['permissions_added']);
        $this->assertSame(['department.view'], $log->properties['permissions_removed']);
    }

    public function test_super_admin_role_cannot_be_edited(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.roles.edit', Role::findByName(config('harma.super_admin_role'))))
            ->assertForbidden();
    }

    public function test_role_manager_cannot_grant_permissions_they_do_not_have(): void
    {
        $this->actingAs($this->userWithPermissions('role.view', 'role.manage', 'client.view'));

        Livewire::test('pages::admin.roles.form')
            ->set('name', 'Escalated')
            ->set('permissions', ['client.view', 'user.create'])
            ->call('save');

        $this->assertFalse(Role::query()->where('name', 'Escalated')->exists());
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.roles.form')
            ->set('name', 'Odd')
            ->set('permissions', ['does.not.exist'])
            ->call('save')
            ->assertHasErrors(['permissions.0']);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        $this->staff()->assignRole($role);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.roles.index')->call('confirmDelete', $role->id)->call('delete');

        $this->assertTrue(Role::query()->whereKey($role->id)->exists());
    }

    public function test_unused_custom_role_can_be_deleted(): void
    {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.roles.index')->call('confirmDelete', $role->id)->call('delete')->assertHasNoErrors();

        $this->assertFalse(Role::query()->whereKey($role->id)->exists());
        $this->assertTrue(Activity::query()->where('event', 'role.deleted')->exists());
    }
}
