<?php

namespace Tests\Feature\Client;

use App\Models\Activity;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_client(): void
    {
        $this->actingAs($this->userWithPermissions('client.view', 'client.create'));

        Livewire::test('pages::clients.index')
            ->call('create')
            ->set('code', 'acme')
            ->set('name', 'PT Acme Indonesia')
            ->set('pic_name', 'Rudi')
            ->set('email', 'rudi@acme.co.id')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clients', ['code' => 'ACME', 'name' => 'PT Acme Indonesia', 'phone' => null, 'is_active' => true]);
        $this->assertTrue(Activity::query()->where('event', 'client.created')->exists());
    }

    public function test_authorized_user_can_update_client(): void
    {
        $client = Client::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->userWithPermissions('client.view', 'client.update'));

        Livewire::test('pages::clients.index')
            ->call('edit', $client->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $client->refresh()->name);

        $log = Activity::query()->where('event', 'client.updated')->firstOrFail();
        $this->assertSame('Old Name', $log->attribute_changes['old']['name']);
        $this->assertSame('New Name', $log->attribute_changes['attributes']['name']);
    }

    public function test_client_validation(): void
    {
        Client::factory()->create(['code' => 'ACME']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::clients.index')
            ->call('create')
            ->set('code', 'ACME')
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['code' => 'unique', 'name' => 'required', 'email' => 'email']);
    }

    public function test_view_only_user_cannot_modify_clients(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->staff());

        $this->get(route('clients.index'))->assertOk()->assertDontSee('Add Client');

        Livewire::test('pages::clients.index')->call('edit', $client->id)->assertForbidden();
        Livewire::test('pages::clients.index')->call('confirmStatus', $client->id)->assertForbidden();
    }

    public function test_client_is_deactivated_not_deleted(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->userWithPermissions('client.view', 'client.deactivate'));

        Livewire::test('pages::clients.index')->call('confirmStatus', $client->id)->call('changeStatus');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => false]);
    }

    public function test_status_filter_and_search(): void
    {
        Client::factory()->create(['name' => 'Alpha Corp']);
        Client::factory()->inactive()->create(['name' => 'Beta Corp']);

        $this->actingAs($this->superAdmin());

        Livewire::test('pages::clients.index')
            ->set('status', 'active')
            ->assertSee('Alpha Corp')
            ->assertDontSee('Beta Corp');

        Livewire::test('pages::clients.index')
            ->set('search', 'beta')
            ->assertSee('Beta Corp')
            ->assertDontSee('Alpha Corp');
    }
}
