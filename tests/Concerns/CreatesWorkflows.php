<?php

namespace Tests\Concerns;

use App\Domain\Approval\Actions\ManageApprovalTemplates;
use App\Domain\Workflow\Actions\CreateWorkflow;
use App\Domain\Workflow\Actions\ManageWorkflowDraft;
use App\Domain\Workflow\Actions\PublishWorkflowVersion;
use App\Domain\Workflow\Actions\UpdateWorkflow;
use App\Enums\WorkflowRuleType;
use App\Models\ApprovalTemplate;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;

/**
 * Builds workflows and approval templates through the real actions.
 */
trait CreatesWorkflows
{
    use CreatesTickets;

    protected function defaultWorkflow(string $entity): Workflow
    {
        return Workflow::query()->where('entity_type', $entity)->where('is_default', true)->sole();
    }

    /**
     * Draft copy of the default workflow of the entity.
     */
    protected function draftCopy(string $entity, string $name = 'Custom Workflow'): WorkflowVersion
    {
        $workflow = app(CreateWorkflow::class)->handle(['name' => $name, 'entity_type' => $entity, 'copy_from_workflow_id' => $this->defaultWorkflow($entity)->id], $this->superAdmin());

        return $workflow->draftVersion()->firstOrFail();
    }

    protected function transitionIn(WorkflowVersion $version, string $fromCode, string $toCode): WorkflowTransition
    {
        return WorkflowTransition::query()->where('workflow_version_id', $version->id)
            ->whereHas('fromState', fn ($query) => $query->where('code', $fromCode))
            ->whereHas('toState', fn ($query) => $query->where('code', $toCode))
            ->with(['rules', 'fromState', 'toState'])
            ->firstOrFail();
    }

    /**
     * Change one transition of a draft (keeps everything not overridden).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function editTransition(WorkflowVersion $draft, WorkflowTransition $transition, array $overrides): WorkflowTransition
    {
        return app(ManageWorkflowDraft::class)->saveTransition($draft, $transition, [
            'from_state_id' => $transition->from_state_id,
            'to_state_id' => $transition->to_state_id,
            'action_label' => $transition->action_label,
            'operation' => $transition->operation,
            'roles' => $transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? [],
            'permissions' => $transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [],
            'requirements' => $transition->rules->reject(fn ($rule) => $rule->type->isWhoRule())->map(fn ($rule) => $rule->type->value)->values()->all(),
            'approval_template_id' => $transition->approval_template_id,
            ...$overrides,
        ], $this->superAdmin());
    }

    protected function publish(WorkflowVersion $version, bool $makeDefault = false): WorkflowVersion
    {
        $published = app(PublishWorkflowVersion::class)->handle($version, $this->superAdmin());

        if ($makeDefault) {
            app(UpdateWorkflow::class)->makeDefault($published->workflow()->firstOrFail(), $this->superAdmin());
        }

        return $published->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    protected function approvalTemplate(array $steps, ?string $entity = null, string $name = 'Review Approval'): ApprovalTemplate
    {
        $templates = app(ManageApprovalTemplates::class);
        $template = $templates->save(null, ['name' => $name, 'entity_type' => $entity], $this->superAdmin());

        foreach ($steps as $index => $step) {
            $templates->saveStep($template, null, ['name' => 'Step '.($index + 1), ...$step]);
        }

        return $template->refresh();
    }

    /**
     * Default ticket workflow replaced by a copy whose In Review → Resolved
     * needs the given approval template.
     */
    protected function ticketResolveWithApproval(ApprovalTemplate $template): WorkflowVersion
    {
        $draft = $this->draftCopy('ticket', 'Ticket with approval');
        $this->editTransition($draft, $this->transitionIn($draft, 'IN_REVIEW', 'RESOLVED'), ['approval_template_id' => $template->id]);

        return $this->publish($draft, makeDefault: true);
    }

    protected function actorFor(User $user): User
    {
        return $user->fresh();
    }
}
