<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs($this->staff());

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_shows_only_permitted_summary_cards(): void
    {
        $this->actingAs($this->staff())
            ->get(route('dashboard'))
            ->assertSee('Departments')
            ->assertDontSee('Active Members')
            ->assertDontSee('Recent Activity');

        $this->actingAs($this->superAdmin())
            ->get(route('dashboard'))
            ->assertSee('Active Members')
            ->assertSee('Recent Activity');
    }

    public function test_user_without_dashboard_permission_is_forbidden(): void
    {
        $this->actingAs($this->userWithPermissions())
            ->get(route('dashboard'))
            ->assertForbidden();
    }
}
