<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Access\PermissionRegistry;
use App\Domain\Workflow\Handlers\TransitionHandlers;
use App\Enums\WorkflowEntity;
use App\Enums\WorkflowRuleType;
use App\Enums\WorkflowVersionStatus;
use App\Models\ApprovalTemplate;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;

/**
 * Checks a version before publishing. Errors block publishing; warnings
 * must be seen by the administrator but may be intentional.
 */
class WorkflowVersionValidator
{
    public function __construct(
        private TransitionHandlers $handlers,
        private WorkflowActorResolver $actors,
    ) {}

    /**
     * @return array{errors: list<string>, warnings: list<string>, summary: array{initial: string|null, states: int, transitions: int, approvals: int}}
     */
    public function validate(WorkflowVersion $version): array
    {
        $version->load(['workflow', 'states.status', 'transitions' => fn ($query) => $query->with(['rules', 'fromState.status', 'toState.status', 'approvalTemplate.steps'])]);
        $entity = $version->workflow->entity_type;
        $errors = [];
        $warnings = [];
        $states = $version->states;
        $transitions = $version->transitions->filter(fn (WorkflowTransition $transition): bool => $transition->is_active);
        $initials = $states->filter(fn (WorkflowState $state): bool => $state->is_initial);

        if ($states->isEmpty()) {
            $errors[] = 'Tambahkan minimal satu state.';
        }

        if ($initials->count() !== 1) {
            $errors[] = 'Workflow harus memiliki tepat satu state awal (saat ini '.$initials->count().').';
        }

        foreach ($states as $state) {
            if ($state->status->scope !== $entity->statusScope()) {
                $errors[] = 'State "'.$state->label().'" memakai status dari scope lain ('.$state->status->scope->value.').';
            }

            if (! $state->status->is_active) {
                $warnings[] = 'State "'.$state->label().'" memakai status yang sedang nonaktif.';
            }
        }

        $handler = $this->handlers->for($entity);
        $roles = array_keys($this->actors->available($entity));
        $permissions = PermissionRegistry::all();

        foreach ($transitions as $transition) {
            $name = '"'.$transition->action_label.'" ('.$transition->fromState->label().' → '.$transition->toState->label().')';

            if ($transition->fromState->workflow_version_id !== $version->id || $transition->toState->workflow_version_id !== $version->id) {
                $errors[] = 'Transition '.$name.' merujuk state dari versi lain.';

                continue;
            }

            if (($problem = $handler->incompatibility($transition->operation, $transition->fromState->status, $transition->toState->status)) !== null) {
                $errors[] = 'Transition '.$name.': '.$problem;
            }

            $who = $transition->rules->filter(fn ($rule): bool => $rule->type->isWhoRule());

            if ($transition->operation !== 'assign' && $who->every(fn ($rule): bool => $rule->values($rule->type === WorkflowRuleType::Permission ? 'permissions' : 'roles') === [])) {
                $errors[] = 'Transition '.$name.' belum menentukan siapa yang boleh menjalankannya.';
            }

            foreach ($transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? [] as $role) {
                if (! in_array($role, $roles, true)) {
                    $errors[] = 'Transition '.$name.' memakai role kontekstual tidak dikenal: '.$role.'.';
                }
            }

            foreach ($transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [] as $permission) {
                if (! in_array($permission, $permissions, true)) {
                    $errors[] = 'Transition '.$name.' memakai permission tidak dikenal: '.$permission.'.';
                }
            }

            if ($transition->requires(WorkflowRuleType::AttachmentRequired) && $entity !== WorkflowEntity::Ticket) {
                $errors[] = 'Transition '.$name.': lampiran hanya tersedia untuk Ticket.';
            }

            if ($transition->requires(WorkflowRuleType::AssigneeRequired) && $entity === WorkflowEntity::Project) {
                $errors[] = 'Transition '.$name.': Project tidak memiliki Main PIC.';
            }

            if ($transition->requires(WorkflowRuleType::ResolutionRequired) && $transition->operation !== 'resolve') {
                $errors[] = 'Transition '.$name.': resolution hanya untuk operasi Resolve.';
            }

            if ($transition->approval_template_id !== null) {
                $errors = [...$errors, ...$this->approvalProblems($transition->approvalTemplate, $entity, $name, $transition)];
            }
        }

        $initial = $initials->first();

        if ($initial !== null) {
            $reachable = $this->reachable($initial, array_values($transitions->all()));

            if (! $transitions->contains(fn (WorkflowTransition $transition): bool => $transition->from_state_id === $initial->id)) {
                $errors[] = 'State awal "'.$initial->label().'" belum memiliki aksi keluar.';
            }

            if ($states->filter(fn (WorkflowState $state): bool => $state->is_terminal)->isNotEmpty()
                && ! $states->contains(fn (WorkflowState $state): bool => $state->is_terminal && isset($reachable[$state->id]))) {
                $errors[] = 'Tidak ada jalur dari state awal ke state akhir.';
            }

            foreach ($states as $state) {
                if (! isset($reachable[$state->id])) {
                    $warnings[] = 'State "'.$state->label().'" tidak dapat dicapai dari state awal.';
                }

                if (! $state->is_terminal && ! $transitions->contains(fn (WorkflowTransition $transition): bool => $transition->from_state_id === $state->id)) {
                    $warnings[] = 'State "'.$state->label().'" tidak memiliki aksi keluar (dead-end) padahal bukan state akhir.';
                }
            }

            if ($entity === WorkflowEntity::Ticket && ! $transitions->contains(fn (WorkflowTransition $transition): bool => $transition->operation === 'assign')) {
                $warnings[] = 'Tidak ada transition "Assign PIC": status tidak berubah saat PIC ditetapkan.';
            }
        }

        $warnings = [...$warnings, ...$this->removedSincePublished($version)];

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'summary' => [
                'initial' => $initial?->label(),
                'states' => $states->count(),
                'transitions' => $transitions->count(),
                'approvals' => $transitions->filter(fn (WorkflowTransition $transition): bool => $transition->approval_template_id !== null)->count(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function approvalProblems(?ApprovalTemplate $template, WorkflowEntity $entity, string $name, WorkflowTransition $transition): array
    {
        if ($template === null || ! $template->is_active) {
            return ['Transition '.$name.' memakai template approval yang tidak aktif.'];
        }

        $problems = [];

        if ($template->entity_type !== null && $template->entity_type !== $entity) {
            $problems[] = 'Transition '.$name.': template approval "'.$template->name.'" untuk '.$template->entity_type->label().'.';
        }

        if (! $template->steps->contains(fn ($step): bool => $step->is_required)) {
            $problems[] = 'Template approval "'.$template->name.'" belum memiliki tahap wajib.';
        }

        if ($transition->operation === 'assign') {
            $problems[] = 'Transition '.$name.': Assign PIC tidak dapat memakai approval.';
        }

        return $problems;
    }

    /**
     * @param  list<WorkflowTransition>  $transitions
     * @return array<int, true>
     */
    private function reachable(WorkflowState $initial, array $transitions): array
    {
        $seen = [$initial->id => true];
        $queue = [$initial->id];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($transitions as $transition) {
                if ($transition->from_state_id === $current && ! isset($seen[$transition->to_state_id])) {
                    $seen[$transition->to_state_id] = true;
                    $queue[] = $transition->to_state_id;
                }
            }
        }

        return $seen;
    }

    /**
     * Informational: what this draft drops compared with the version in use.
     *
     * @return list<string>
     */
    private function removedSincePublished(WorkflowVersion $version): array
    {
        if ($version->status !== WorkflowVersionStatus::Draft) {
            return [];
        }

        $published = WorkflowVersion::query()
            ->where('workflow_id', $version->workflow_id)
            ->where('status', WorkflowVersionStatus::Published->value)
            ->with(['states', 'transitions.fromState', 'transitions.toState'])
            ->first();

        if ($published === null) {
            return [];
        }

        $draftStates = $version->states->pluck('status_id')->all();
        $draftPaths = $version->transitions->map(fn (WorkflowTransition $transition): string => $transition->fromState->status_id.'>'.$transition->toState->status_id)->all();
        $removedStates = $published->states->reject(fn (WorkflowState $state): bool => in_array($state->status_id, $draftStates, true))->count();
        $removedTransitions = $published->transitions->reject(fn (WorkflowTransition $transition): bool => in_array($transition->fromState->status_id.'>'.$transition->toState->status_id, $draftPaths, true))->count();

        if ($removedStates === 0 && $removedTransitions === 0) {
            return [];
        }

        return ['Draft ini menghapus '.$removedStates.' state dan '.$removedTransitions.' transition dibanding versi yang berlaku. Perubahan ini hanya berlaku untuk transaksi baru yang memakai versi terbaru.'];
    }
}
