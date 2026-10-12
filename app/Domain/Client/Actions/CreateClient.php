<?php

namespace App\Domain\Client\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class CreateClient
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, pic_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function handle(array $data): Client
    {
        return DB::transaction(function () use ($data): Client {
            $client = Client::create($data);

            $this->audit->recordCreated('client.created', 'Membuat client '.$client->name, $client);

            return $client;
        });
    }
}
