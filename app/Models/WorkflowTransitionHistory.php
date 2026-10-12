<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable record of an executed state change.
 *
 * @property int $id
 * @property int $workflow_version_id
 * @property int|null $workflow_transition_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $from_state_id
 * @property int $to_state_id
 * @property int|null $actor_id
 * @property string|null $comment
 * @property array<string, mixed>|null $metadata
 * @property Carbon $executed_at
 */
class WorkflowTransitionHistory extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workflow transition history is immutable.'));
        static::deleting(fn () => throw new LogicException('Workflow transition history is immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<WorkflowTransition, $this>
     */
    public function transition(): BelongsTo
    {
        return $this->belongsTo(WorkflowTransition::class, 'workflow_transition_id');
    }

    /**
     * @return BelongsTo<WorkflowState, $this>
     */
    public function fromState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'from_state_id');
    }

    /**
     * @return BelongsTo<WorkflowState, $this>
     */
    public function toState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'to_state_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
