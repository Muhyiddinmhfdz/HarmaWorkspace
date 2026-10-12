<?php

namespace App\Models;

use App\Enums\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of a workflow once published. Records keep the
 * version they were created with (ADR-067).
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $version_number
 * @property WorkflowVersionStatus $status
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowVersionStatus::class,
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<WorkflowState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(WorkflowState::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === WorkflowVersionStatus::Draft;
    }

    public function label(): string
    {
        return 'v'.$this->version_number;
    }
}
