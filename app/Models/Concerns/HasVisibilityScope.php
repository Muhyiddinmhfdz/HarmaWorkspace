<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for resource visibility.
 *
 * Models define `scopeVisibleTo()` once. List queries call the scope and
 * policies call isVisibleTo(), which reuses the same scope, so list pages,
 * detail pages, search and future APIs can never disagree.
 */
trait HasVisibilityScope
{
    /**
     * Limit the query to records the given user may see.
     *
     * @param  Builder<static>  $query
     */
    abstract public function scopeVisibleTo(Builder $query, User $user): void;

    /**
     * Determine whether the given user may see this record.
     */
    public function isVisibleTo(User $user): bool
    {
        return static::query()
            ->visibleTo($user)
            ->whereKey($this->getKey())
            ->exists();
    }
}
