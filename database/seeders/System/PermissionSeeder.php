<?php

namespace Database\Seeders\System;

use App\Domain\Access\Actions\SyncPermissionRegistry;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(SyncPermissionRegistry $sync): void
    {
        $sync->handle();
    }
}
