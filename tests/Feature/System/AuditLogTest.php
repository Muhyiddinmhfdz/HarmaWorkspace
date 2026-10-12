<?php

namespace Tests\Feature\System;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_requires_permission(): void
    {
        $this->actingAs($this->staff())->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->userWithPermissions('audit.view'))->get(route('admin.audit.index'))->assertOk();
    }

    public function test_audit_entries_record_actor_ip_and_changes(): void
    {
        $admin = $this->superAdmin(['name' => 'Admin Satu']);
        $this->actingAs($admin);

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', 'DEV')
            ->set('name', 'Programmer')
            ->call('save');

        $department = Department::query()->where('code', 'DEV')->firstOrFail();

        $component = Livewire::test('pages::admin.audit.index')
            ->assertSee('Admin Satu')
            ->assertSee('department.created');

        $entry = $component->instance()->entries->first();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame($department->id, $entry->subject_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertSame('Programmer', $entry->attribute_changes['attributes']['name']);

        $component->call('showDetail', $entry->id)->assertSee('Programmer');
    }

    public function test_audit_log_can_be_filtered_by_event(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')->call('create')->set('code', 'DEV')->set('name', 'Programmer')->call('save');
        Livewire::test('pages::clients.index')->call('create')->set('code', 'ACME')->set('name', 'Acme')->call('save');

        Livewire::test('pages::admin.audit.index')
            ->set('event', 'client.created')
            ->assertSee('Membuat client Acme')
            ->assertDontSee('Membuat department Programmer');
    }
}
