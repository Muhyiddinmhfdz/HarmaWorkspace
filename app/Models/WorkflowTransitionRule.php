<?php

namespace App\Models;

use App\Enums\WorkflowRuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A data-only rule of a transition (see WorkflowRuleType for config shapes).
 *
 * @property int $id
 * @property int $workflow_transition_id
 * @property WorkflowRuleType $type
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowTransitionRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkflowRuleType::class,
            'config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowTransition, $this>
     */
    public function transition(): BelongsTo
    {
        return $this->belongsTo(WorkflowTransition::class, 'workflow_transition_id');
    }

    /**
     * String list from the config (permissions or roles).
     *
     * @return list<string>
     */
    public function values(string $key): array
    {
        $values = $this->config[$key] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
