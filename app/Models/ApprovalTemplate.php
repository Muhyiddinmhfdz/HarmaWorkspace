<?php

namespace App\Models;

use App\Enums\WorkflowEntity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Reusable sequential approval definition. Requests snapshot its steps, so
 * changes only affect new requests (ADR-070). Deactivated, never deleted,
 * once used.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property WorkflowEntity|null $entity_type
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class ApprovalTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => WorkflowEntity::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ApprovalTemplateStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalTemplateStep::class)->orderBy('step_order');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function isInUse(): bool
    {
        return $this->requests()->exists() || $this->transitions()->exists();
    }
}
