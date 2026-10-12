<?php

namespace Tests\Feature\System;

use App\Domain\System\Search\GlobalSearch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_only_returns_modules_the_user_may_view(): void
    {
        User::factory()->create(['name' => 'Acme Person']);
        Client::factory()->create(['name' => 'Acme Corp']);

        $staffGroups = collect(app(GlobalSearch::class)->search($this->staff(), 'acme'))->pluck('group');
        $this->assertSame(['Clients'], $staffGroups->all());

        $adminGroups = collect(app(GlobalSearch::class)->search($this->superAdmin(), 'acme'))->pluck('group');
        $this->assertEqualsCanonicalizing(['Members', 'Clients'], $adminGroups->all());
    }

    public function test_short_terms_return_nothing(): void
    {
        $this->assertSame([], app(GlobalSearch::class)->search($this->superAdmin(), 'a'));
    }
}
