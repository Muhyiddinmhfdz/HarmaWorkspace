<?php

namespace App\Domain\Workflow\Support;

use App\Enums\WorkflowRuleType;
use App\Models\Status;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Support\Str;

/**
 * Low-level writer for version definitions (states, transitions, rules),
 * shared by the default bootstrap, cloning and the builder actions.
 * Callers are responsible for authorization and draft checks.
 */
class WorkflowDefinitionWriter
{
    public function state(WorkflowVersion $version, Status $status, bool $initial = false, ?int $sortOrder = null, ?string $label = null): WorkflowState
    {
        $state = new WorkflowState;
        $state->forceFill([
            'workflow_version_id' => $version->id,
            'status_id' => $status->id,
            'code' => $status->code,
            'label_override' => $label,
            'sort_order' => $sortOrder ?? $status->sort_order,
            'is_initial' => $initial,
            'is_terminal' => $status->is_terminal,
        ])->save();

        return $state->setRelation('status', $status);
    }

    /**
     * @param  array<string, array<string, list<string>>|null>  $rules  type => config
     * @param  array{operation?: string, confirmation_message?: string|null, approval_template_id?: int|null, sort_order?: int, code?: string|null, created_by?: int|null}  $attributes
     */
    public function transition(WorkflowVersion $version, WorkflowState $from, WorkflowState $to, string $label, array $rules = [], array $attributes = []): WorkflowTransition
    {
        $transition = new WorkflowTransition;
        $transition->forceFill([
            'workflow_version_id' => $version->id,
            'from_state_id' => $from->id,
            'to_state_id' => $to->id,
            'code' => $attributes['code'] ?? $this->code($version, $from, $to),
            'action_label' => $label,
            'operation' => $attributes['operation'] ?? 'move',
            'confirmation_message' => $attributes['confirmation_message'] ?? null,
            'approval_template_id' => $attributes['approval_template_id'] ?? null,
            'sort_order' => $attributes['sort_order'] ?? 0,
            'is_active' => true,
            'created_by' => $attributes['created_by'] ?? null,
        ])->save();

        $this->rules($transition, $rules);

        return $transition;
    }

    /**
     * Replace the rules of a transition.
     *
     * @param  array<string, array<string, list<string>>|null>  $rules
     */
    public function rules(WorkflowTransition $transition, array $rules): void
    {
        $transition->rules()->delete();

        foreach ($rules as $type => $config) {
            $transition->rules()->forceCreate(['type' => WorkflowRuleType::from($type), 'config' => $config]);
        }

        $transition->unsetRelation('rules');
    }

    private function code(WorkflowVersion $version, WorkflowState $from, WorkflowState $to): string
    {
        $base = Str::limit(strtoupper($from->code.'_TO_'.$to->code), 55, '');
        $code = $base;
        $suffix = 2;

        while (WorkflowTransition::query()->where('workflow_version_id', $version->id)->where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }
}
