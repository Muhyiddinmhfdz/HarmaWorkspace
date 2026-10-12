<?php

namespace App\Models;

use App\Enums\ApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $approval_template_id
 * @property int $step_order
 * @property string $name
 * @property ApproverType $approver_type
 * @property array<string, mixed>|null $approver_config
 * @property int $minimum_approvals
 * @property bool $allow_self_approval
 * @property bool $is_required
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApprovalTemplateStep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'approver_config' => 'array',
            'step_order' => 'integer',
            'minimum_approvals' => 'integer',
            'allow_self_approval' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ApprovalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ApprovalTemplate::class, 'approval_template_id');
    }
}
