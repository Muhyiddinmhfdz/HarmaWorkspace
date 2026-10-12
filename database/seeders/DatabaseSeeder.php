<?php

namespace Database\Seeders;

use Database\Seeders\Development\DevelopmentSeeder;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * System defaults always run. Demo data runs only in the local environment;
     * it is never seeded into production automatically.
     */
    public function run(): void
    {
        $this->call(SystemSeeder::class);

        if (app()->isLocal()) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
