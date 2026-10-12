<?php

use App\Domain\System\Actions\UpdateSettings;
use App\Domain\System\Settings\SettingKey;
use App\Domain\System\Settings\Settings;
use Livewire\Attributes\Title;
use App\Livewire\Concerns\WithFeedback;
use Livewire\Component;

new #[Title('System Settings')] class extends Component {
    use WithFeedback;

    /**
     * Form values keyed by SettingKey value (dots replaced, see key()).
     *
     * @var array<string, string|int|bool>
     */
    public array $values = [];

    public function mount(Settings $settings): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);

        foreach (SettingKey::cases() as $key) {
            $this->values[$this->key($key)] = $settings->get($key);
        }
    }

    /**
     * Livewire treats dots in property paths as nesting, so keys use "__".
     */
    public function key(SettingKey $key): string
    {
        return str_replace('.', '__', $key->value);
    }

    public function canManage(): bool
    {
        return auth()->user()->can('settings.manage');
    }

    public function preview(string $prefix): string
    {
        $parts = [strtoupper((string) $prefix)];

        if ((bool) ($this->values[$this->key(SettingKey::NumberResetsYearly)] ?? false)) {
            $parts[] = (string) now()->year;
        }

        $padding = max(1, min(8, (int) ($this->values[$this->key(SettingKey::NumberPadding)] ?? 4)));
        $parts[] = str_pad('1', $padding, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    public function save(string $group, UpdateSettings $updateSettings): void
    {
        abort_unless($this->canManage(), 403);

        $keys = SettingKey::inGroup($group);
        abort_if($keys === [], 404);

        foreach ([SettingKey::ProjectNumberPrefix, SettingKey::TaskNumberPrefix, SettingKey::TicketNumberPrefix] as $prefixKey) {
            if (in_array($prefixKey, $keys, true)) {
                $this->values[$this->key($prefixKey)] = strtoupper(trim((string) $this->values[$this->key($prefixKey)]));
            }
        }

        $rules = [];
        $attributes = [];

        foreach ($keys as $key) {
            $rules['values.'.$this->key($key)] = $key->rules();
            $attributes['values.'.$this->key($key)] = $key->label();
        }

        $validated = $this->validate($rules, attributes: $attributes)['values'];

        $updateSettings->handle(
            collect($validated)->mapWithKeys(fn ($value, string $field): array => [str_replace('__', '.', $field) => $value])->all(),
            auth()->user(),
        );

        $this->notify('success', 'Pengaturan berhasil disimpan.');
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header title="System Settings" description="Konfigurasi umum HarmaWorkspace. Setiap perubahan tercatat di audit log." />

    @unless ($this->canManage())
        <flux:callout icon="information-circle" class="mb-6" heading="Anda hanya dapat melihat pengaturan." text="Perubahan membutuhkan permission settings.manage." />
    @endunless

    <div class="space-y-6">
        {{-- General & Branding --}}
        <form wire:submit="save('general')" class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ui.form-section title="General" description="Identitas organisasi yang tampil di aplikasi.">
                <flux:input wire:model="values.{{ $this->key(App\Domain\System\Settings\SettingKey::OrganizationName) }}" label="Organization name" badge="Required" :disabled="! $this->canManage()" />
                @if ($this->canManage())
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="save('general')">Save</span>
                            <span wire:loading wire:target="save('general')">Saving...</span>
                        </flux:button>
                    </div>
                @endif
            </x-ui.form-section>
        </form>

        <form wire:submit="save('branding')" class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ui.form-section title="Branding" description="Teks pendamping nama HarmaWorkspace di halaman login dan sidebar.">
                <flux:input wire:model="values.{{ $this->key(App\Domain\System\Settings\SettingKey::AppSubtitle) }}" label="Application subtitle" badge="Required" :disabled="! $this->canManage()" />
                @if ($this->canManage())
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="save('branding')">Save</span>
                            <span wire:loading wire:target="save('branding')">Saving...</span>
                        </flux:button>
                    </div>
                @endif
            </x-ui.form-section>
        </form>

        {{-- Numbering --}}
        <form wire:submit="save('numbering')" class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ui.form-section title="Numbering" description="Format nomor dokumen. Mulai dipakai saat modul Project (Phase 2), Task (Phase 3), dan Ticket (Phase 4) aktif. Nomor yang sudah terbit tidak pernah dipakai ulang.">
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        App\Domain\System\Settings\SettingKey::ProjectNumberPrefix,
                        App\Domain\System\Settings\SettingKey::TaskNumberPrefix,
                        App\Domain\System\Settings\SettingKey::TicketNumberPrefix,
                    ] as $prefixKey)
                        <div wire:key="prefix-{{ $prefixKey->value }}">
                            <flux:input wire:model.live.debounce.300ms="values.{{ $this->key($prefixKey) }}" :label="$prefixKey->label()" class="uppercase" maxlength="10" :disabled="! $this->canManage()" />
                            <flux:text size="sm" class="mt-1 font-mono">{{ $this->preview((string) $values[$this->key($prefixKey)]) }}</flux:text>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.300ms="values.{{ $this->key(App\Domain\System\Settings\SettingKey::NumberPadding) }}" type="number" min="3" max="8" label="Sequence digits" :disabled="! $this->canManage()" />
                    <div class="pt-7">
                        <flux:switch wire:model.live="values.{{ $this->key(App\Domain\System\Settings\SettingKey::NumberResetsYearly) }}" label="Reset sequence every year" :disabled="! $this->canManage()" />
                    </div>
                </div>

                @if ($this->canManage())
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="save('numbering')">Save</span>
                            <span wire:loading wire:target="save('numbering')">Saving...</span>
                        </flux:button>
                    </div>
                @endif
            </x-ui.form-section>
        </form>

        {{-- Security (read-only: these values come from code and environment configuration) --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ui.form-section title="Security" description="Kebijakan keamanan saat ini. Nilai ini diatur melalui konfigurasi server, bukan dari halaman ini.">
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <x-ui.detail-item label="Public registration">Nonaktif — akun dibuat oleh administrator</x-ui.detail-item>
                    <x-ui.detail-item label="Login throttle">5 percobaan per menit per email & IP</x-ui.detail-item>
                    <x-ui.detail-item label="Password policy">{{ app()->isProduction() ? 'Minimal 12 karakter, huruf besar & kecil, angka, simbol, belum pernah bocor' : 'Minimal 8 karakter, huruf dan angka (non-production)' }}</x-ui.detail-item>
                    <x-ui.detail-item label="Session lifetime">{{ config('session.lifetime') }} menit</x-ui.detail-item>
                    <x-ui.detail-item label="Two-factor authentication">Tersedia, diaktifkan oleh masing-masing user di menu Security</x-ui.detail-item>
                    <x-ui.detail-item label="Inactive users">Tidak dapat login; sesi aktif dicabut saat dinonaktifkan</x-ui.detail-item>
                </dl>
            </x-ui.form-section>
        </div>
    </div>
</div>
