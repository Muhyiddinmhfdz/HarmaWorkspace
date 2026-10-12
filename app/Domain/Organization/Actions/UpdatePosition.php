<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdatePosition
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, level?: int|null, sort_order?: int}  $data
     */
    public function handle(Position $position, array $data): Position
    {
        return DB::transaction(function () use ($position, $data): Position {
            if ($position->isCodeLocked() && Str::upper(trim($data['code'])) !== $position->code) {
                throw new BusinessRuleException('Code tidak dapat diubah karena position sudah digunakan.');
            }

            $position->update($data);

            $this->audit->recordChanges('position.updated', 'Mengubah position '.$position->name, $position);

            return $position;
        });
    }
}
