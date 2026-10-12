<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $parent_id
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'parent_id', 'sort_order'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasActiveStatus, HasFactory, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * All memberships, including ended ones (history).
     *
     * @return HasMany<DepartmentMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(DepartmentMembership::class);
    }

    /**
     * @return HasMany<DepartmentMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('ended_at');
    }

    public function isInUse(): bool
    {
        return $this->memberships()->exists() || $this->children()->exists();
    }

    /**
     * IDs of this department and all of its descendants.
     *
     * Used to prevent hierarchy cycles. Department trees are small, so a
     * breadth-first walk is simpler than a recursive CTE.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = array_values(array_map(intval(...), Department::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all()));

            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
