<?php

namespace App\Domain\System\Numbering;

use App\Domain\System\Settings\SettingKey;
use App\Domain\System\Settings\Settings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Issues document numbers such as PRJ-2026-0001.
 *
 * The counter row is locked with SELECT ... FOR UPDATE, so concurrent requests
 * receive different numbers; `last_number` only grows, so numbers are never
 * reused (a rolled-back transaction leaves a harmless gap). Call it inside the
 * transaction that creates the numbered record.
 */
class NumberGenerator
{
    public function __construct(private Settings $settings) {}

    public function next(string $scope, SettingKey $prefixKey): string
    {
        return DB::transaction(function () use ($scope, $prefixKey): string {
            $yearly = (bool) $this->settings->get(SettingKey::NumberResetsYearly);
            $period = $yearly ? (string) Date::now()->year : 'all';
            $now = Date::now();

            DB::table('number_sequences')->insertOrIgnore([
                'scope' => $scope,
                'period' => $period,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $current = (int) DB::table('number_sequences')
                ->where('scope', $scope)
                ->where('period', $period)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('number_sequences')
                ->where('scope', $scope)
                ->where('period', $period)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            $parts = [(string) $this->settings->get($prefixKey)];

            if ($yearly) {
                $parts[] = $period;
            }

            $parts[] = str_pad((string) $next, (int) $this->settings->get(SettingKey::NumberPadding), '0', STR_PAD_LEFT);

            return implode('-', $parts);
        });
    }
}
