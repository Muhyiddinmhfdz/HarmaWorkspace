<?php

namespace Tests\Feature\Ui;

use App\Domain\Organization\Actions\CreateDepartment;
use App\Domain\Shared\InputSanitizer;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Every action reports its outcome as a toast, input is sanitized, and
 * unexpected errors never reach the user as a technical page.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitizer_strips_tags_and_control_characters_but_keeps_passwords(): void
    {
        $sanitizer = new InputSanitizer;

        $this->assertSame('alert(1) Acme', $sanitizer->clean("  <script>alert(1)</script> Acme\u{0007} "));
        $this->assertSame("line 1\nline 2", $sanitizer->clean("line 1\r\nline 2"));
        $this->assertSame(' <b>secret</b> ', $sanitizer->clean(' <b>secret</b> ', 'password'));
        $this->assertSame(['name' => 'Bold', 'password' => ' raw '], $sanitizer->clean(['name' => '<b>Bold</b>', 'password' => ' raw ']));
    }

    public function test_html_is_stripped_before_saving(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::clients.index')
            ->call('create')
            ->set('code', ' acme ')
            ->set('name', '<img src=x onerror=alert(1)>PT Acme')
            ->set('notes', '<script>steal()</script>Catatan')
            ->call('save')
            ->assertHasNoErrors();

        $client = Client::query()->where('code', 'ACME')->firstOrFail();
        $this->assertSame('PT Acme', $client->name);
        $this->assertSame('steal()Catatan', $client->notes);
    }

    public function test_success_is_reported_as_toast(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', 'DEV')
            ->set('name', 'Programmer')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Department berhasil ditambahkan.');
    }

    public function test_validation_errors_are_reported_as_toast_and_kept_on_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', '')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['code', 'name'])
            ->assertDispatched('notify', type: 'error', title: 'Periksa kembali isian');
    }

    public function test_business_rule_violation_is_reported_as_toast(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->actingAs($admin);

        Livewire::test('pages::admin.users.index')
            ->call('confirmStatus', $admin->id)
            ->call('changeStatus')
            ->assertDispatched('notify', type: 'error', message: 'Anda tidak dapat menonaktifkan akun Anda sendiri.');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_unexpected_error_is_logged_and_reported_as_friendly_toast(): void
    {
        $this->actingAs($this->superAdmin());

        $this->app->bind(CreateDepartment::class, fn () => throw new RuntimeException('Database is down'));

        Livewire::test('pages::admin.departments.index')
            ->call('create')
            ->set('code', 'OPS')
            ->set('name', 'Operational')
            ->call('save')
            ->assertOk()
            ->assertDispatched('notify', type: 'error', message: 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi administrator.');
    }

    public function test_redirecting_actions_flash_a_toast_for_the_next_page(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.users.form')
            ->set('name', 'Toast User')
            ->set('username', 'toast')
            ->set('password', 'secret-pass-123')
            ->set('password_confirmation', 'secret-pass-123')
            ->set('memberships', [])
            ->call('save')
            ->assertRedirect();

        $this->assertSame('success', session('notify.type'));

        $this->get(route('admin.users.show', User::query()->where('username', 'toast')->first()))
            ->assertSee('data-flash-notify', false);
    }
}
