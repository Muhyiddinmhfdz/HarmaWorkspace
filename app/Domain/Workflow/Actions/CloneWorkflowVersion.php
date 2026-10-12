<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Support\WorkflowDefinitionWriter;
use App\Enums\WorkflowVersionStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;

/**
 * Copies a version's states, transitions and rules into a new draft of the
 * target workflow (new draft of the same workflow, or a duplicated workflow).
 */
class CloneWorkflowVersion
{
    public function __construct(private WorkflowDefinitionWriter $writer) {}

    public function handle(?WorkflowVersion $source, Workflow $target, User $actor): WorkflowVersion
    {
        $draft = new WorkflowVersion;
        $draft->forceFill([
            'workflow_id' => $target->id,
            'version_number' => ((int) WorkflowVersion::query()->where('workflow_id', $target->id)->max('version_number')) + 1,
            'status' => WorkflowVersionStatus::Draft,
            'created_by' => $actor->id,
        ])->save();

        if ($source === null) {
            return $draft;
        }

        $source->loadMissing(['states.status', 'transitions.rules']);
        $map = [];

        foreach ($source->states as $state) {
            $copy = $this->writer->state($draft, $state->status, $state->is_initial, $state->sort_order, $state->label_override);
            $map[$state->id] = $copy;
        }

        $source->transitions->each(function (WorkflowTransition $transition) use ($draft, $map, $actor): void {
            $rules = [];

            foreach ($transition->rules as $rule) {
                $rules[$rule->type->value] = $rule->config;
            }

            $this->writer->transition($draft, $map[$transition->from_state_id], $map[$transition->to_state_id], $transition->action_label, $rules, [
                'code' => $transition->code,
                'operation' => $transition->operation,
                'confirmation_message' => $transition->confirmation_message,
                'approval_template_id' => $transition->approval_template_id,
                'sort_order' => $transition->sort_order,
                'created_by' => $actor->id,
            ]);
        });

        return $draft->setRelation('states', collect(array_values($map))->sortBy(fn (WorkflowState $state): int => $state->sort_order)->values());
    }
}
