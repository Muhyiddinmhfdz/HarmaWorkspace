<?php

namespace App\Policies;

use App\Domain\Approval\Support\ApproverResolver;
use App\Enums\ApproverType;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * An approval is visible to its participants — the requester, the approvers
 * designated by its steps and those who decided — and to approval.view-all
 * holders who can see the record. Participants without access to the record
 * only see the request summary; nobody else learns anything (ADR-070).
 */
class ApprovalRequestPolicy
{
    public function __construct(private ApproverResolver $approvers) {}

    public function viewAny(User $user): bool
    {
        return $user->can('approval.view') || $user->can('approval.view-all');
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        $approvable = $request->approvable()->first();

        if ($approvable === null || ! $user->is_active) {
            return false;
        }

        if ($request->requested_by === $user->id) {
            return true;
        }

        if ($user->can('approval.view-all') && $user->can('view', $approvable)) {
            return true;
        }

        return $request->steps()->with('decisions')->get()->contains(fn ($step): bool => $step->decisions->contains('user_id', $user->id)
            || ($this->approvers->matches($user, $step, $approvable) && ($step->approver_type !== ApproverType::Permission || $user->can('view', $approvable))));
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        $step = $request->currentStep()->first();
        $approvable = $request->approvable()->first();

        return $request->isPending() && $step !== null && $approvable !== null
            && $user->can('approval.decide')
            && $this->approvers->isEligible($user, $step, $request, $approvable);
    }

    public function cancel(User $user, ApprovalRequest $request): bool
    {
        return $request->isPending() && $this->view($user, $request)
            && ($request->requested_by === $user->id || $user->can('approval.view-all'));
    }
}
