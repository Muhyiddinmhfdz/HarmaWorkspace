<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\System\SystemSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed permissions and system roles for every test using RefreshDatabase.
     */
    protected bool $seed = true;

    protected string $seeder = SystemSeeder::class;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    protected function superAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create($attributes);
    }

    /**
     * A user holding exactly the given permissions (granted directly).
     */
    protected function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->withRole('Staff')->create();
        $user->syncRoles([]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    protected function staff(): User
    {
        return User::factory()->withRole('Staff')->create();
    }
}
