<?php

namespace App\Domain\Client\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateClient
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, pic_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function handle(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data): Client {
            if ($client->isCodeLocked() && Str::upper(trim($data['code'])) !== $client->code) {
                throw new BusinessRuleException('Code tidak dapat diubah karena client sudah digunakan.');
            }

            $client->update($data);

            $this->audit->recordChanges('client.updated', 'Mengubah client '.$client->name, $client);

            return $client;
        });
    }
}
