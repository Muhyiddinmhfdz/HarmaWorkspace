<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared behaviour for records that are deactivated instead of deleted.
 *
 * `is_active` is intentionally not mass assignable; it changes only through
 * a dedicated status action so every change is audited.
 */
trait HasActiveStatus
{
    /**
     * Limit the query to active records.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * Filter by an optional status value coming from list filters ("active" / "inactive").
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function status(Builder $query, ?string $status): void
    {
        match ($status) {
            'active' => $query->where($this->qualifyColumn('is_active'), true),
            'inactive' => $query->where($this->qualifyColumn('is_active'), false),
            default => null,
        };
    }
}
