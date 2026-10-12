<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;

/**
 * Data the application needs in every environment. Idempotent: safe to run
 * on every deployment (`php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force`).
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            ProjectMasterSeeder::class,
            TaskMasterSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
