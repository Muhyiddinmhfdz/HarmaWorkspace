<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('client.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can('client.view');
    }

    public function create(User $user): bool
    {
        return $user->can('client.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('client.update');
    }

    public function activate(User $user, Client $client): bool
    {
        return $user->can('client.activate');
    }

    public function deactivate(User $user, Client $client): bool
    {
        return $user->can('client.deactivate');
    }
}
