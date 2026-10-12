<?php

namespace App\Models;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit trail entry. Extends the Spatie model to capture the request origin.
 *
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            if (App::runningInConsole() && ! App::runningUnitTests()) {
                return;
            }

            $activity->ip_address ??= Request::ip();
            $activity->user_agent ??= Str::limit((string) Request::userAgent(), 500, '');
        });
    }
}
