<?php

namespace Tests\Feature\System;

use App\Domain\System\Settings\SettingKey;
use App\Domain\System\Settings\Settings;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_used_until_overridden(): void
    {
        $this->assertSame('PRJ', app(Settings::class)->get(SettingKey::ProjectNumberPrefix));
        $this->assertSame(4, app(Settings::class)->get(SettingKey::NumberPadding));
    }

    public function test_settings_manager_can_update_numbering(): void
    {
        $this->actingAs($this->userWithPermissions('settings.view', 'settings.manage'));

        Livewire::test('pages::settings.index')
            ->set('values.numbering__project_prefix', 'proj')
            ->set('values.numbering__padding', 5)
            ->call('save', 'numbering')
            ->assertHasNoErrors();

        $this->assertSame('PROJ', app(Settings::class)->get(SettingKey::ProjectNumberPrefix));
        $this->assertSame(5, app(Settings::class)->get(SettingKey::NumberPadding));
        $this->assertTrue(Activity::query()->where('event', 'settings.updated')->exists());
    }

    public function test_invalid_prefix_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::settings.index')
            ->set('values.numbering__project_prefix', 'PR J!')
            ->call('save', 'numbering')
            ->assertHasErrors(['values.numbering__project_prefix']);
    }

    public function test_view_only_user_cannot_save_settings(): void
    {
        $this->actingAs($this->userWithPermissions('settings.view'));

        Livewire::test('pages::settings.index')
            ->set('values.general__organization_name', 'Changed')
            ->call('save', 'general')
            ->assertForbidden();

        $this->assertSame('HARMA DIGITAL', app(Settings::class)->get(SettingKey::OrganizationName));
    }
}
