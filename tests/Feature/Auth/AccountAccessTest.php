<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Internal-only access rules: no public registration, inactive accounts are
 * locked out, and logins are recorded.
 */
class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'outsider@example.com']);
        $this->get(route('login'))->assertDontSee('Register')->assertDontSee('Sign up');
    }

    public function test_login_uses_username_not_email(): void
    {
        $user = $this->staff();

        $this->post(route('login.store'), ['username' => strtoupper($user->username), 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        $this->post(route('login.store'), ['username' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_system_seeder_creates_default_super_admin(): void
    {
        config(['harma.super_admin' => ['name' => 'Super Admin', 'username' => 'superadmin', 'email' => null, 'password' => '123123123']]);

        $this->seed(SystemSeeder::class);

        $this->post(route('login.store'), ['username' => 'superadmin', 'password' => '123123123']);

        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isSuperAdmin());
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'Akun Anda tidak aktif. Hubungi administrator.']);

        $this->assertGuest();
    }

    public function test_inactive_status_is_not_revealed_without_the_correct_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['username' => 'Username atau password salah.']);
    }

    public function test_user_deactivated_during_session_is_logged_out_on_next_request(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_successful_login_records_last_login_time(): void
    {
        $user = $this->staff();
        $this->assertNull($user->last_login_at);

        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_guest_is_redirected_from_every_internal_page(): void
    {
        foreach (['dashboard', 'admin.users.index', 'admin.departments.index', 'clients.index', 'master-data.index', 'admin.roles.index', 'admin.audit.index', 'settings.index', 'profile.edit'] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('login'));
        }
    }
}
