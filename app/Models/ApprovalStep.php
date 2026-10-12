<?php

namespace App\Models;

use App\Enums\ApprovalDecisionType;
use App\Enums\ApprovalStepStatus;
use App\Enums\ApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Snapshot of a template step inside one request. Approvers are resolved
 * live from approver_type + approver_context when a decision is made.
 *
 * @property int $id
 * @property int $approval_request_id
 * @property int $step_order
 * @property string $name
 * @property ApproverType $approver_type
 * @property array<string, mixed>|null $approver_context
 * @property int $minimum_approvals
 * @property bool $allow_self_approval
 * @property bool $is_required
 * @property ApprovalStepStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApprovalStep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'approver_context' => 'array',
            'status' => ApprovalStepStatus::class,
            'step_order' => 'integer',
            'minimum_approvals' => 'integer',
            'allow_self_approval' => 'boolean',
            'is_required' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('decided_at')->orderBy('id');
    }

    /**
     * Requires the decisions relation.
     */
    public function approvalsCount(): int
    {
        return $this->decisions->filter(fn (ApprovalDecision $decision): bool => $decision->decision === ApprovalDecisionType::Approve)->count();
    }
}
