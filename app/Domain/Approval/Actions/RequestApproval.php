<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Support\ApprovalAudit;
use App\Domain\Approval\Support\ApproverResolver;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalTemplate;
use App\Models\ApprovalTemplateStep;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First stage of a transition that needs approval (ADR-074): the record does
 * not change; a request with a SNAPSHOT of the template steps is created
 * (ADR-070), and the first step starts. One pending request per record and
 * transition (database-enforced).
 */
class RequestApproval
{
    /**
     * Input keys replayed when the approved transition executes.
     *
     * @var list<string>
     */
    private const array REPLAYED_INPUT = ['comment', 'reason', 'notes', 'resolution_id', 'primary_ticket_id', 'rejection_reason_id', 'actual_end'];

    public function __construct(
        private ApproverResolver $approvers,
        private ApprovalAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function forTransition(Model $approvable, WorkflowTransition $transition, User $actor, array $input = []): ApprovalRequest
    {
        $template = ApprovalTemplate::query()->with('steps')->find($transition->approval_template_id);

        if ($template === null || ! $template->is_active || $template->steps->isEmpty()) {
            throw new BusinessRuleException('Template approval untuk aksi ini tidak tersedia. Hubungi administrator.');
        }

        return DB::transaction(function () use ($approvable, $transition, $actor, $input, $template): ApprovalRequest {
            $pending = ApprovalRequest::query()
                ->where('approvable_type', $approvable->getMorphClass())
                ->where('approvable_id', $approvable->getKey())
                ->where('workflow_transition_id', $transition->id)
                ->where('status', ApprovalRequestStatus::Pending->value)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw new BusinessRuleException('Masih ada permintaan approval yang berjalan untuk aksi ini.');
            }

            $request = new ApprovalRequest;
            $request->forceFill([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'approval_template_id' => $template->id,
                'workflow_transition_id' => $transition->id,
                'title' => Str::limit($transition->action_label, 150, ''),
                'status' => ApprovalRequestStatus::Pending,
                'input' => array_intersect_key($input, array_flip(self::REPLAYED_INPUT)) ?: null,
                'requested_by' => $actor->id,
                'requested_at' => Date::now(),
            ]);

            try {
                $request->save();
            } catch (QueryException $e) {
                throw new BusinessRuleException('Masih ada permintaan approval yang berjalan untuk aksi ini.', previous: $e);
            }

            $template->steps->each(function (ApprovalTemplateStep $step) use ($request): void {
                $snapshot = new ApprovalStep;
                $snapshot->forceFill([
                    'approval_request_id' => $request->id,
                    'step_order' => $step->step_order,
                    'name' => $step->name,
                    'approver_type' => $step->approver_type,
                    'approver_context' => $step->approver_config,
                    'minimum_approvals' => max(1, $step->minimum_approvals),
                    'allow_self_approval' => $step->allow_self_approval,
                    'is_required' => $step->is_required,
                    'status' => ApprovalStepStatus::Waiting,
                ])->save();
            });

            if (! $this->activateNext($request, $approvable)) {
                throw new BusinessRuleException('Tidak ada tahap approval yang dapat dijalankan. Hubungi administrator.');
            }

            $this->audit->record($request, $approvable, 'approval.requested', 'Meminta approval untuk "'.$transition->action_label.'"', [
                'note' => $input['comment'] ?? $input['reason'] ?? null,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Start the next waiting step; optional steps without any possible
     * approver are skipped. Returns false when no step is left.
     */
    public function activateNext(ApprovalRequest $request, Model $approvable): bool
    {
        $steps = $request->steps()->where('status', ApprovalStepStatus::Waiting->value)->orderBy('step_order')->get();

        foreach ($steps as $step) {
            $candidates = $this->approvers->candidates($step, $request, $approvable);

            if (! $step->is_required && $candidates !== null && $candidates->isEmpty()) {
                $step->forceFill(['status' => ApprovalStepStatus::Skipped, 'completed_at' => Date::now()])->save();

                continue;
            }

            $step->forceFill(['status' => ApprovalStepStatus::Pending, 'started_at' => Date::now()])->save();
            $request->forceFill(['current_step_order' => $step->step_order])->save();

            return true;
        }

        $request->forceFill(['current_step_order' => null])->save();

        return false;
    }
}
