<?php

namespace Tests\Feature\Profile;

use App\Models\Department;
use App\Models\DepartmentMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_departments(): void
    {
        $user = $this->staff();
        $department = Department::factory()->create(['name' => 'Programmer']);
        DepartmentMembership::factory()->primary()->create(['user_id' => $user->id, 'department_id' => $department->id]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Programmer');
    }

    public function test_user_can_update_name_and_phone(): void
    {
        $user = $this->staff();

        $this->actingAs($user);

        Livewire::test('pages::profile.profile')
            ->set('name', 'Test User')
            ->set('phone', '08123456789')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('08123456789', $user->phone);
    }

    public function test_user_cannot_change_own_email_from_profile(): void
    {
        $user = $this->staff();
        $originalEmail = $user->email;

        $this->actingAs($user);

        try {
            Livewire::test('pages::profile.profile')
                ->set('email', 'hijack@example.com')
                ->call('updateProfileInformation');

            $this->fail('The profile component must not expose an email property.');
        } catch (PublicPropertyNotFoundException) {
            // Expected: email is not editable from the profile page.
        }

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_self_service_account_deletion_is_not_available(): void
    {
        $this->actingAs($this->staff())
            ->get(route('profile.edit'))
            ->assertDontSee('Delete account');

        $this->assertFalse(view()->exists('pages.profile.delete-user-form'));
    }
}
