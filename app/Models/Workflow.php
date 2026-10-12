<?php

namespace App\Models;

use App\Enums\WorkflowEntity;
use App\Enums\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A process for one entity type (project, task, ticket). A workflow is usable
 * when it is active AND has a published version. Never hard-deleted once a
 * version has been published.
 *
 * @property int $id
 * @property WorkflowEntity $entity_type
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class Workflow extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => WorkflowEntity::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version_number');
    }

    /**
     * The version new records start on.
     *
     * @return HasOne<WorkflowVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', WorkflowVersionStatus::Published->value);
    }

    /**
     * @return HasOne<WorkflowVersion, $this>
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', WorkflowVersionStatus::Draft->value);
    }

    /**
     * Active workflows with a published version.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('publishedVersion');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forEntity(Builder $query, WorkflowEntity $entity): void
    {
        $query->where('entity_type', $entity->value);
    }
}
