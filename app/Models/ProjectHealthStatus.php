<?php

namespace App\Models;

use App\Models\Concerns\HasActiveStatus;
use App\Models\Concerns\HasMasterCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Project health indicator. `level` orders severity (higher = worse);
 * `is_default` is used for new projects and `represents_completion` when a
 * project is completed.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property string|null $icon
 * @property int $level
 * @property int $sort_order
 * @property bool $is_default
 * @property bool $represents_completion
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'color', 'icon', 'level', 'sort_order', 'is_default', 'represents_completion'])]
class ProjectHealthStatus extends Model
{
    use HasActiveStatus, HasMasterCode;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'represents_completion' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return Project::query()->where('health_status_id', $this->id)->exists();
    }
}
