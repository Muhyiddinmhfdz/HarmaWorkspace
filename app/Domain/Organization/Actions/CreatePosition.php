<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

class CreatePosition
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, level?: int|null, sort_order?: int}  $data
     */
    public function handle(array $data): Position
    {
        return DB::transaction(function () use ($data): Position {
            $position = Position::create($data);

            $this->audit->recordCreated('position.created', 'Membuat position '.$position->name, $position);

            return $position;
        });
    }
}
