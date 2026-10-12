<?php

namespace App\Models;

use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A request to approve something on a record — typically a workflow
 * transition, executed once the last step approves. Nothing is mass
 * assignable; only approval actions write it.
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int|null $approval_template_id
 * @property int|null $workflow_transition_id
 * @property string $title
 * @property ApprovalRequestStatus $status
 * @property int|null $current_step_order
 * @property array<string, mixed>|null $input
 * @property string|null $outcome_note
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApprovalRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalRequestStatus::class,
            'input' => 'array',
            'current_step_order' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ApprovalTemplate::class, 'approval_template_id');
    }

    /**
     * @return BelongsTo<WorkflowTransition, $this>
     */
    public function transition(): BelongsTo
    {
        return $this->belongsTo(WorkflowTransition::class, 'workflow_transition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    /**
     * @return HasOne<ApprovalStep, $this>
     */
    public function currentStep(): HasOne
    {
        return $this->hasOne(ApprovalStep::class)->where('status', ApprovalStepStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalRequestStatus::Pending;
    }
}
