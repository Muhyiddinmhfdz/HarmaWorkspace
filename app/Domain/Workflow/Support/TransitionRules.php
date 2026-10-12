<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\WorkflowRuleType;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Evaluates the data-only rules of a transition (ADR-069). "Who" rules
 * (permission, contextual role) combine with OR — a transition without any
 * "who" rule can only be run by the Super Admin bypass. Requirement rules
 * must all be satisfied. Record visibility is always checked separately.
 */
class TransitionRules
{
    public function __construct(private WorkflowActorResolver $actors) {}

    public function canPerform(User $user, Model $record, WorkflowTransition $transition): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (Gate::forUser($user)->allows('workflow.bypass-rules')) {
            return true;
        }

        $permissions = $transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [];
        $roles = $transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? [];

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return $roles !== [] && $this->actors->holdsAny($user, $record, $roles);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function validateInput(Model $record, WorkflowTransition $transition, array $input): void
    {
        if ($transition->requires(WorkflowRuleType::CommentRequired) && blank($input['comment'] ?? null)) {
            throw new BusinessRuleException('Komentar wajib diisi untuk aksi "'.$transition->action_label.'".');
        }

        if ($transition->requires(WorkflowRuleType::ReasonRequired) && blank($input['reason'] ?? null)) {
            throw new BusinessRuleException('Alasan wajib diisi untuk aksi "'.$transition->action_label.'".');
        }

        if ($transition->requires(WorkflowRuleType::ResolutionRequired) && blank($input['resolution_id'] ?? null)) {
            throw new BusinessRuleException('Resolution wajib dipilih.');
        }

        if ($transition->requires(WorkflowRuleType::AttachmentRequired)
            && (! method_exists($record, 'attachments') || ! $record->attachments()->exists())) {
            throw new BusinessRuleException('Tambahkan lampiran terlebih dahulu sebelum menjalankan aksi "'.$transition->action_label.'".');
        }

        if ($transition->requires(WorkflowRuleType::AssigneeRequired)
            && (! method_exists($record, 'picAssignment') || ! $record->picAssignment()->exists())) {
            throw new BusinessRuleException('Tetapkan Main PIC terlebih dahulu sebelum menjalankan aksi "'.$transition->action_label.'".');
        }
    }

    /**
     * Generic text fields the transition modal must collect.
     *
     * @return list<array{name: string, label: string, required: bool}>
     */
    public function textFields(WorkflowTransition $transition): array
    {
        $fields = [];

        if ($transition->requires(WorkflowRuleType::ReasonRequired)) {
            $fields[] = ['name' => 'reason', 'label' => 'Reason', 'required' => true];
        }

        if ($transition->requires(WorkflowRuleType::CommentRequired)) {
            $fields[] = ['name' => 'comment', 'label' => 'Comment', 'required' => true];
        }

        return $fields;
    }
}
