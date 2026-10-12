<?php

namespace App\Domain\System\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\System\Settings\SettingKey;
use App\Domain\System\Settings\Settings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateSettings
{
    public function __construct(
        private Settings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  Validated values keyed by SettingKey value.
     */
    public function handle(array $values, User $actor): void
    {
        $old = [];
        $new = [];

        foreach ($values as $keyName => $value) {
            $key = SettingKey::from($keyName);
            $value = $key->cast($value);
            $current = $this->settings->get($key);

            if ($current !== $value) {
                $old[$key->value] = $current;
                $new[$key->value] = $value;
            }
        }

        if ($new === []) {
            return;
        }

        DB::transaction(function () use ($old, $new, $actor): void {
            foreach ($new as $keyName => $value) {
                Setting::query()->updateOrCreate(['key' => $keyName], ['value' => $value, 'updated_by' => $actor->id]);
            }

            $this->audit->record('settings.updated', 'Mengubah system settings', old: $old, new: $new);
        });

        $this->settings->flush();
    }
}
