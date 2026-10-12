<?php

namespace App\Domain\Client\Rules;

use App\Domain\MasterData\Rules\MasterRules;
use App\Models\Client;

class ClientRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?Client $client = null): array
    {
        return [
            'code' => MasterRules::code(Client::class, $client?->id),
            'name' => ['required', 'string', 'max:150'],
            'pic_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
