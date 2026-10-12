<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Support\ApprovalAudit;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw a pending request (by its requester, or an approval
 * administrator when e.g. a required approver is unavailable). A reason is
 * mandatory; decisions already given stay in history.
 */
class CancelApprovalRequest
{
    public function __construct(private ApprovalAudit $audit) {}

    public function handle(ApprovalRequest $request, User $actor, ?string $reason): ApprovalRequest
    {
        if (blank($reason)) {
            throw new BusinessRuleException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                throw new BusinessRuleException('Approval ini sudah selesai. Muat ulang data terbaru.');
            }

            $now = Date::now();
            $request->steps()->whereIn('status', [ApprovalStepStatus::Pending->value, ApprovalStepStatus::Waiting->value])
                ->update(['status' => ApprovalStepStatus::Skipped->value, 'completed_at' => $now]);
            $request->forceFill([
                'status' => ApprovalRequestStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by' => $actor->id,
                'current_step_order' => null,
                'outcome_note' => mb_substr((string) $reason, 0, 500),
            ])->save();

            $this->audit->record($request, $request->approvable()->firstOrFail(), 'approval.cancelled', 'Membatalkan permintaan approval "'.$request->title.'"', ['reason' => $reason]);

            return $request;
        });
    }
}
