<?php

namespace App\Domain\System\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Read access to system settings. Stored overrides are cached; missing keys
 * fall back to the defaults declared in SettingKey.
 */
class Settings
{
    private const string CACHE_KEY = 'harma.settings';

    public function get(SettingKey $key): string|int|bool
    {
        $stored = $this->stored();

        return array_key_exists($key->value, $stored)
            ? $key->cast($stored[$key->value])
            : $key->default();
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function all(): array
    {
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = $this->get($key);
        }

        return $values;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
