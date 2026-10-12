<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

/**
 * Master records are identified by an uppercase business code. Logic may rely on
 * the code, so it becomes read-only once the record is referenced (see isInUse()).
 */
trait HasMasterCode
{
    /**
     * Normalise the code to trimmed uppercase.
     *
     * @return Attribute<string, string|null>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : Str::upper(trim($value)),
        );
    }

    /**
     * Determine whether other records reference this master record.
     */
    abstract public function isInUse(): bool;

    /**
     * Determine whether the code may still be changed.
     */
    public function isCodeLocked(): bool
    {
        return $this->exists && $this->isInUse();
    }
}
