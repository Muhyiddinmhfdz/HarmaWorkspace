<?php

namespace App\Domain\Approval\Support;

use App\Enums\ApprovalRequestStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Waiting for me": pending requests whose current step the user may decide
 * now. Approvers are resolved live (contextual rules), so candidates are
 * loaded with their relations once and filtered by ApproverResolver.
 */
class ApprovalInbox
{
    private const int SCAN_LIMIT = 500;

    public function __construct(private ApproverResolver $approvers) {}

    /**
     * @return Collection<int, ApprovalRequest>
     */
    public function waitingFor(User $user): Collection
    {
        if (! $user->is_active || ! $user->can('approval.decide')) {
            return collect();
        }

        return ApprovalRequest::query()
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->with(['approvable', 'requester:id,name', 'currentStep.decisions', 'steps'])
            ->oldest('requested_at')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $request->currentStep !== null
                && $request->approvable !== null
                && $this->approvers->isEligible($user, $request->currentStep, $request, $request->approvable))
            ->values();
    }

    public function countFor(User $user): int
    {
        return $this->waitingFor($user)->count();
    }
}
