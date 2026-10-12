<?php

namespace App\Models;

use App\Enums\ApprovalDecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An approver's decision. Immutable: a wrong decision is corrected by a new
 * request, never by editing history.
 *
 * @property int $id
 * @property int $approval_step_id
 * @property int $user_id
 * @property ApprovalDecisionType $decision
 * @property string|null $comment
 * @property Carbon $decided_at
 */
class ApprovalDecision extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approval decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Approval decisions are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecisionType::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
