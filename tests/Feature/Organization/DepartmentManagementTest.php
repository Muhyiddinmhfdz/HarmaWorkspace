<?php

namespace Tests\Feature\Organization;

use App\Models\Activity;
use App\Models\Department;
use App\Models\DepartmentMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_list_renders_for_authorized_user(): void
    {
        Department::factory()->create(['code' => 'DEV', 'name' => 'Programmer']);

        $this->actingAs($this->userWithPermissions('department.view'))
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee('Programmer');
    }

    public function test_empty_state_is_shown_when_there_are_no_departments(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.departments.index'))
            ->assertSee('Belum ada Department.');
    }

    public function test_authorized_user_can_create_department(): void
    {
        $this->actingAs($this->userWithPermissions('department.view', 'department.create'));

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', 'dev')
            ->set('name', 'Programmer')
            ->set('sort_order', '10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', ['code' => 'DEV', 'name' => 'Programmer', 'is_active' => true]);
        $this->assertTrue(Activity::query()->where('event', 'department.created')->exists());
    }

    public function test_duplicate_department_code_is_rejected(): void
    {
        Department::factory()->create(['code' => 'DEV']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', 'dev')
            ->set('name', 'Another')
            ->call('save')
            ->assertHasErrors(['code' => 'unique']);

        $this->assertSame(1, Department::query()->where('code', 'DEV')->count());
    }

    public function test_required_fields_are_validated_with_indonesian_messages(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', '')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required'])
            ->assertSee('Name wajib diisi.');
    }

    public function test_user_without_create_permission_cannot_create_department(): void
    {
        $this->actingAs($this->userWithPermissions('department.view'));

        Livewire::test('pages::admin.departments.index')
            ->set('code', 'HACK')
            ->set('name', 'Hack')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('departments', ['code' => 'HACK']);
    }

    public function test_department_can_be_deactivated_without_losing_members(): void
    {
        $department = Department::factory()->create();
        $membership = DepartmentMembership::factory()->primary()->create(['department_id' => $department->id]);

        $this->actingAs($this->userWithPermissions('department.view', 'department.deactivate'));

        Livewire::test('pages::admin.departments.index')
            ->call('confirmStatus', $department->id)
            ->call('changeStatus')
            ->assertHasNoErrors();

        $this->assertFalse($department->refresh()->is_active);
        $this->assertDatabaseHas('department_memberships', ['id' => $membership->id, 'ended_at' => null]);
        $this->assertTrue(Activity::query()->where('event', 'department.deactivated')->where('subject_id', $department->id)->exists());
    }

    public function test_inactive_department_is_not_offered_as_parent(): void
    {
        $inactive = Department::factory()->inactive()->create(['name' => 'Old Division']);
        Department::factory()->create(['name' => 'Technology']);

        $this->actingAs($this->superAdmin());

        $options = Livewire::test('pages::admin.departments.index')->call('create')->instance()->parentOptions;

        $this->assertFalse($options->contains('id', $inactive->id));
        $this->assertTrue($options->contains('name', 'Technology'));
    }

    public function test_department_cannot_become_its_own_ancestor(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('edit', $parent->id)
            ->set('parent_id', (string) $child->id)
            ->call('save');

        $this->assertNull($parent->refresh()->parent_id);
    }

    public function test_code_is_locked_once_department_is_in_use(): void
    {
        $department = Department::factory()->create(['code' => 'DEV']);
        DepartmentMembership::factory()->create(['department_id' => $department->id]);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('edit', $department->id)
            ->set('code', 'PROG')
            ->call('save');

        $this->assertSame('DEV', $department->refresh()->code);
    }

    public function test_department_detail_lists_members_and_leaders(): void
    {
        $department = Department::factory()->create();
        $leader = DepartmentMembership::factory()->leader()->create(['department_id' => $department->id]);

        $this->actingAs($this->userWithPermissions('department.view'))
            ->get(route('admin.departments.show', $department))
            ->assertOk()
            ->assertSee($leader->user->name)
            ->assertSee('Leader');
    }
}
