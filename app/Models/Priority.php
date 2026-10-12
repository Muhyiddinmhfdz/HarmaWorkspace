<?php

namespace App\Models;

use App\Enums\MasterScope;
use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Priority master shared by projects, tasks and tickets. Logic compares
 * `level` (higher = more urgent), never the display name.
 *
 * @property int $id
 * @property MasterScope $scope
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $level
 * @property string $color
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope', 'code', 'name', 'description', 'level', 'color', 'icon', 'sort_order'])]
class Priority extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => MasterScope::class,
            'level' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forScope(Builder $query, MasterScope $scope): void
    {
        $query->where($this->qualifyColumn('scope'), $scope->value);
    }

    public function isInUse(): bool
    {
        return match ($this->scope) {
            MasterScope::Project => Project::query()->where('priority_id', $this->id)->exists(),
            default => false,
        };
    }
}
