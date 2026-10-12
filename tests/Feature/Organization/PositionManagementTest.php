<?php

namespace Tests\Feature\Organization;

use App\Models\DepartmentMembership;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PositionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_permission_is_required_to_open_positions(): void
    {
        $this->actingAs($this->staff())->get(route('admin.positions.index'))->assertForbidden();
        $this->actingAs($this->userWithPermissions('position.view'))->get(route('admin.positions.index'))->assertOk();
    }

    public function test_position_crud_follows_permissions(): void
    {
        $this->actingAs($this->userWithPermissions('position.view'));

        Livewire::test('pages::admin.positions.index')->call('create')->assertForbidden();

        $this->actingAs($this->userWithPermissions('position.view', 'position.create', 'position.update'));

        Livewire::test('pages::admin.positions.index')
            ->call('create')
            ->set('code', 'mgr')
            ->set('name', 'Manager')
            ->set('level', '40')
            ->call('save')
            ->assertHasNoErrors();

        $position = Position::query()->where('code', 'MGR')->firstOrFail();
        $this->assertSame(40, $position->level);

        Livewire::test('pages::admin.positions.index')
            ->call('edit', $position->id)
            ->set('name', 'Senior Manager')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Senior Manager', $position->refresh()->name);

        Livewire::test('pages::admin.positions.index')
            ->call('confirmStatus', $position->id)
            ->assertForbidden();
    }

    public function test_position_can_be_deactivated_and_reactivated(): void
    {
        $position = Position::factory()->create();

        $this->actingAs($this->userWithPermissions('position.view', 'position.activate', 'position.deactivate'));

        Livewire::test('pages::admin.positions.index')->call('confirmStatus', $position->id)->call('changeStatus');
        $this->assertFalse($position->refresh()->is_active);

        Livewire::test('pages::admin.positions.index')->call('confirmStatus', $position->id)->call('changeStatus');
        $this->assertTrue($position->refresh()->is_active);
    }

    public function test_used_position_keeps_its_code(): void
    {
        $position = Position::factory()->create(['code' => 'STAFF']);
        DepartmentMembership::factory()->create(['position_id' => $position->id]);

        $this->assertTrue($position->isCodeLocked());
    }
}
