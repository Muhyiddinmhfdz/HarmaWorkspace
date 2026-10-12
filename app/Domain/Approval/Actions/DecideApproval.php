<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Support\ApprovalAudit;
use App\Domain\Approval\Support\ApproverResolver;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\InputSanitizer;
use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Enums\ApprovalDecisionType;
use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Record an approver's decision on the current step, on the locked request
 * (two approvers clicking at once cannot both complete a step):
 *
 * - approve: when the step reaches its minimum approvals the next step
 *   starts; after the last step the request is approved and the workflow
 *   transition executes (re-validated; if the record changed meanwhile the
 *   request is cancelled with an explanation, nothing is forced — ADR-074);
 * - reject / revision: comment required; the request ends and the record
 *   stays where it is.
 *
 * Decisions are immutable (ADR-071: no self approval unless the step allows).
 */
class DecideApproval
{
    public function __construct(
        private ApproverResolver $approvers,
        private RequestApproval $requests,
        private ApprovalAudit $audit,
        private ExecuteWorkflowTransition $transitions,
        private InputSanitizer $sanitizer,
    ) {}

    /**
     * @return string Friendly outcome message.
     */
    public function handle(ApprovalRequest $request, ApprovalDecisionType $decision, User $user, ?string $comment = null): string
    {
        $comment = trim((string) $this->sanitizer->clean((string) $comment));

        if ($decision->requiresComment() && $comment === '') {
            throw new BusinessRuleException($decision === ApprovalDecisionType::Reject ? 'Alasan penolakan wajib diisi.' : 'Catatan revisi wajib diisi.');
        }

        return DB::transaction(function () use ($request, $decision, $user, $comment): string {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                throw new BusinessRuleException('Approval ini sudah selesai. Muat ulang data terbaru.');
            }

            $step = $request->steps()->where('status', ApprovalStepStatus::Pending->value)->lockForUpdate()->first()
                ?? throw new BusinessRuleException('Tidak ada tahap approval yang aktif. Muat ulang data terbaru.');
            $approvable = $request->approvable()->firstOrFail();

            if (! $step->allow_self_approval && $request->requested_by === $user->id) {
                throw new BusinessRuleException('Anda tidak dapat menyetujui permintaan Anda sendiri.');
            }

            if (! $this->approvers->isEligible($user, $step, $request, $approvable)) {
                throw new AuthorizationException('Anda bukan approver untuk tahap ini.');
            }

            $record = new ApprovalDecision;
            $record->forceFill([
                'approval_step_id' => $step->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'comment' => $comment !== '' ? $comment : null,
                'decided_at' => Date::now(),
            ])->save();

            return match ($decision) {
                ApprovalDecisionType::Approve => $this->approve($request, $step, $approvable, $user, $comment),
                ApprovalDecisionType::Reject => $this->end($request, $step, $approvable, $user, $comment, ApprovalStepStatus::Rejected, ApprovalRequestStatus::Rejected, 'approval.rejected', 'menolak'),
                ApprovalDecisionType::Revision => $this->end($request, $step, $approvable, $user, $comment, ApprovalStepStatus::RevisionRequested, ApprovalRequestStatus::RevisionRequested, 'approval.revision_requested', 'meminta revisi untuk'),
            };
        });
    }

    private function approve(ApprovalRequest $request, ApprovalStep $step, Model $approvable, User $user, string $comment): string
    {
        $this->audit->record($request, $approvable, 'approval.approved', 'Menyetujui tahap "'.$step->name.'" untuk "'.$request->title.'"', ['note' => $comment ?: null, 'step' => $step->name]);

        if ($this->approvers->approvalsOn($step) < $step->minimum_approvals) {
            return 'Persetujuan Anda tercatat. Menunggu approver lain pada tahap ini.';
        }

        $step->forceFill(['status' => ApprovalStepStatus::Approved, 'completed_at' => Date::now()])->save();

        if ($this->requests->activateNext($request, $approvable)) {
            return 'Tahap "'.$step->name.'" disetujui. Approval berlanjut ke tahap berikutnya.';
        }

        $request->forceFill(['status' => ApprovalRequestStatus::Approved, 'completed_at' => Date::now()])->save();

        if ($request->workflow_transition_id === null) {
            return 'Approval selesai.';
        }

        try {
            DB::transaction(fn () => $this->transitions->executeApproved($request));
        } catch (BusinessRuleException $e) {
            $request->forceFill([
                'status' => ApprovalRequestStatus::Cancelled,
                'cancelled_at' => Date::now(),
                'outcome_note' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            $this->audit->record($request, $approvable, 'approval.cancelled', 'Approval "'.$request->title.'" dibatalkan sistem karena aksi tidak dapat dijalankan', ['reason' => $e->getMessage()]);

            return 'Approval tercatat, tetapi aksi tidak dijalankan: '.$e->getMessage();
        }

        return 'Approval selesai. "'.$request->title.'" telah dijalankan.';
    }

    private function end(ApprovalRequest $request, ApprovalStep $step, Model $approvable, User $user, string $comment, ApprovalStepStatus $stepStatus, ApprovalRequestStatus $requestStatus, string $event, string $verb): string
    {
        $now = Date::now();
        $step->forceFill(['status' => $stepStatus, 'completed_at' => $now])->save();
        $request->steps()->where('status', ApprovalStepStatus::Waiting->value)->update(['status' => ApprovalStepStatus::Skipped->value, 'completed_at' => $now]);
        $request->forceFill(['status' => $requestStatus, 'completed_at' => $now, 'current_step_order' => null])->save();

        $this->audit->record($request, $approvable, $event, ucfirst($verb).' "'.$request->title.'" pada tahap "'.$step->name.'"', ['reason' => $comment, 'step' => $step->name]);

        return $requestStatus === ApprovalRequestStatus::Rejected ? 'Permintaan approval ditolak.' : 'Revisi diminta. Peminta perlu memperbaiki lalu mengajukan ulang.';
    }
}
