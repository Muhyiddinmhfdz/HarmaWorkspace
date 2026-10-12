<?php

namespace App\Models;

use Database\Factories\DepartmentMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership in a department. Department leadership is contextual
 * (`is_leader`), not a global role. Ending a membership sets `ended_at`.
 *
 * @property int $id
 * @property int $user_id
 * @property int $department_id
 * @property int|null $position_id
 * @property bool $is_primary
 * @property bool $is_leader
 * @property Carbon|null $joined_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'department_id', 'position_id', 'is_primary', 'is_leader', 'joined_at', 'ended_at'])]
class DepartmentMembership extends Model
{
    /** @use HasFactory<DepartmentMembershipFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_leader' => 'boolean',
            'joined_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Limit the query to memberships that have not ended.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('ended_at'));
    }
}
