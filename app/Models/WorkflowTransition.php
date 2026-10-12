<?php

namespace App\Models;

use App\Enums\WorkflowRuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An action from one state to another. `operation` names the domain
 * behaviour (side effects) the transition triggers, e.g. "resolve".
 *
 * @property int $id
 * @property int $workflow_version_id
 * @property int $from_state_id
 * @property int $to_state_id
 * @property string $code
 * @property string $action_label
 * @property string $operation
 * @property string|null $confirmation_message
 * @property int|null $approval_template_id
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowTransition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
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
     * @return BelongsTo<ApprovalTemplate, $this>
     */
    public function approvalTemplate(): BelongsTo
    {
        return $this->belongsTo(ApprovalTemplate::class);
    }

    /**
     * @return HasMany<WorkflowTransitionRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(WorkflowTransitionRule::class);
    }

    /**
     * Requires the rules relation.
     */
    public function rule(WorkflowRuleType $type): ?WorkflowTransitionRule
    {
        return $this->rules->first(fn (WorkflowTransitionRule $rule): bool => $rule->type === $type);
    }

    public function requires(WorkflowRuleType $type): bool
    {
        return $this->rule($type) !== null;
    }

    public function requiresApproval(): bool
    {
        return $this->approval_template_id !== null;
    }
}
