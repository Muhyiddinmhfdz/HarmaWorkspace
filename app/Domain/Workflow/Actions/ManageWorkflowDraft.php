<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Access\PermissionRegistry;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\InputSanitizer;
use App\Domain\Workflow\Handlers\TransitionHandlers;
use App\Domain\Workflow\Support\WorkflowActorResolver;
use App\Domain\Workflow\Support\WorkflowDefinitionWriter;
use App\Models\ApprovalTemplate;
use App\Models\Status;
use App\Models\User;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Structured builder for DRAFT versions only (published versions are
 * immutable). Inputs are validated against finite lists: statuses of the
 * entity scope, contextual roles of the entity, known permissions,
 * requirement types and domain operations — nothing executable is stored.
 */
class ManageWorkflowDraft
{
    /**
     * @var list<string>
     */
    public const array REQUIREMENTS = ['comment_required', 'reason_required', 'attachment_required', 'assignee_required', 'resolution_required'];

    public function __construct(
        private WorkflowDefinitionWriter $writer,
        private WorkflowActorResolver $actors,
        private TransitionHandlers $handlers,
        private InputSanitizer $sanitizer,
    ) {}

    /**
     * @param  array{status_id: int, label_override?: string|null, is_initial?: bool, sort_order?: int|null}  $data
     */
    public function saveState(WorkflowVersion $version, ?WorkflowState $state, array $data): WorkflowState
    {
        $this->ensureDraft($version);
        $entity = $version->workflow()->firstOrFail()->entity_type;
        $status = Status::query()->forScope($entity->statusScope())->find($data['status_id'])
            ?? throw new BusinessRuleException('Status harus berasal dari master '.$entity->label().' Status.');

        if ($state === null && ! $status->is_active) {
            throw new BusinessRuleException('Status '.$status->name.' sedang nonaktif dan tidak dapat dipilih.');
        }

        if (WorkflowState::query()->where('workflow_version_id', $version->id)->where('status_id', $status->id)->when($state, fn ($query) => $query->whereKeyNot($state->id))->exists()) {
            throw new BusinessRuleException('Status '.$status->name.' sudah dipakai di workflow ini.');
        }

        return DB::transaction(function () use ($version, $state, $data, $status): WorkflowState {
            if ($data['is_initial'] ?? false) {
                WorkflowState::query()->where('workflow_version_id', $version->id)->when($state, fn ($query) => $query->whereKeyNot($state->id))->update(['is_initial' => false]);
            }

            $label = filled($data['label_override'] ?? null) ? (string) $this->sanitizer->clean($data['label_override']) : null;

            if ($state === null) {
                return $this->writer->state($version, $status, (bool) ($data['is_initial'] ?? false), $data['sort_order'] ?? null, $label);
            }

            $this->ensureBelongs($version, $state);
            $state->forceFill([
                'status_id' => $status->id,
                'code' => $status->code,
                'label_override' => $label,
                'sort_order' => $data['sort_order'] ?? $state->sort_order,
                'is_initial' => (bool) ($data['is_initial'] ?? false),
                'is_terminal' => $status->is_terminal,
            ])->save();

            return $state;
        });
    }

    /**
     * Removes the state and the transitions that use it.
     */
    public function removeState(WorkflowVersion $version, WorkflowState $state): void
    {
        $this->ensureDraft($version);
        $this->ensureBelongs($version, $state);

        DB::transaction(function () use ($version, $state): void {
            WorkflowTransition::query()->where('workflow_version_id', $version->id)
                ->where(fn ($query) => $query->where('from_state_id', $state->id)->orWhere('to_state_id', $state->id))
                ->delete();
            $state->delete();
        });
    }

    /**
     * @param  array{from_state_id: int, to_state_id: int, action_label: string, operation: string, roles?: list<string>, permissions?: list<string>, requirements?: list<string>, approval_template_id?: int|null, confirmation_message?: string|null, sort_order?: int|null}  $data
     */
    public function saveTransition(WorkflowVersion $version, ?WorkflowTransition $transition, array $data, User $actor): WorkflowTransition
    {
        $this->ensureDraft($version);
        $entity = $version->workflow()->firstOrFail()->entity_type;
        $from = WorkflowState::query()->where('workflow_version_id', $version->id)->with('status')->find($data['from_state_id']);
        $to = WorkflowState::query()->where('workflow_version_id', $version->id)->with('status')->find($data['to_state_id']);

        if ($from === null || $to === null) {
            throw new BusinessRuleException('State asal dan tujuan harus berasal dari versi ini.');
        }

        if ($from->id === $to->id) {
            throw new BusinessRuleException('State asal dan tujuan tidak boleh sama.');
        }

        $handler = $this->handlers->for($entity);

        if (! array_key_exists($data['operation'], $handler->operations())) {
            throw new BusinessRuleException('Perilaku (operation) tidak dikenal.');
        }

        if (($problem = $handler->incompatibility($data['operation'], $from->status, $to->status)) !== null) {
            throw new BusinessRuleException($problem);
        }

        $duplicate = WorkflowTransition::query()->where('workflow_version_id', $version->id)
            ->where('from_state_id', $from->id)->where('to_state_id', $to->id)
            ->when($transition, fn ($query) => $query->whereKeyNot($transition->id))
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleException('Sudah ada transition dari '.$from->label().' ke '.$to->label().'.');
        }

        $roles = array_values(array_intersect($data['roles'] ?? [], array_keys($this->actors->available($entity))));
        $permissions = array_values(array_intersect($data['permissions'] ?? [], PermissionRegistry::all()));
        $requirements = array_values(array_intersect($data['requirements'] ?? [], self::REQUIREMENTS));

        if ($roles === [] && $permissions === [] && $data['operation'] !== 'assign') {
            throw new BusinessRuleException('Tentukan siapa yang boleh menjalankan aksi ini (role kontekstual atau permission).');
        }

        $templateId = filled($data['approval_template_id'] ?? null) ? (int) $data['approval_template_id'] : null;

        if ($templateId !== null && ! ApprovalTemplate::query()->whereKey($templateId)->where('is_active', true)->where(fn ($query) => $query->whereNull('entity_type')->orWhere('entity_type', $entity->value))->exists()) {
            throw new BusinessRuleException('Template approval tidak aktif atau tidak sesuai entity.');
        }

        $rules = [];

        if ($roles !== []) {
            $rules['context_role'] = ['roles' => $roles];
        }

        if ($permissions !== []) {
            $rules['permission'] = ['permissions' => $permissions];
        }

        foreach ($requirements as $requirement) {
            $rules[$requirement] = null;
        }

        $label = (string) $this->sanitizer->clean($data['action_label']);
        $confirmation = filled($data['confirmation_message'] ?? null) ? (string) $this->sanitizer->clean($data['confirmation_message']) : null;

        return DB::transaction(function () use ($version, $transition, $from, $to, $label, $data, $rules, $templateId, $confirmation, $actor): WorkflowTransition {
            if ($transition === null) {
                return $this->writer->transition($version, $from, $to, $label, $rules, [
                    'operation' => $data['operation'],
                    'confirmation_message' => $confirmation,
                    'approval_template_id' => $templateId,
                    'sort_order' => $data['sort_order'] ?? ((int) WorkflowTransition::query()->where('workflow_version_id', $version->id)->max('sort_order')) + 10,
                    'created_by' => $actor->id,
                ]);
            }

            if ($transition->workflow_version_id !== $version->id) {
                throw new BusinessRuleException('Transition tidak berasal dari versi ini.');
            }

            $transition->forceFill([
                'from_state_id' => $from->id,
                'to_state_id' => $to->id,
                'action_label' => $label,
                'operation' => $data['operation'],
                'confirmation_message' => $confirmation,
                'approval_template_id' => $templateId,
                'sort_order' => $data['sort_order'] ?? $transition->sort_order,
            ])->save();
            $this->writer->rules($transition, $rules);

            return $transition;
        });
    }

    public function removeTransition(WorkflowVersion $version, WorkflowTransition $transition): void
    {
        $this->ensureDraft($version);

        if ($transition->workflow_version_id !== $version->id) {
            throw new BusinessRuleException('Transition tidak berasal dari versi ini.');
        }

        $transition->delete();
    }

    private function ensureDraft(WorkflowVersion $version): void
    {
        if (! $version->isDraft()) {
            throw new BusinessRuleException('Versi yang sudah dipublikasikan tidak dapat diubah. Buat draft baru untuk melakukan perubahan.');
        }
    }

    private function ensureBelongs(WorkflowVersion $version, WorkflowState $state): void
    {
        if ($state->workflow_version_id !== $version->id) {
            throw new BusinessRuleException('State tidak berasal dari versi ini.');
        }
    }
}
