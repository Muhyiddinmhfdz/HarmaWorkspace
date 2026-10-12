<?php

namespace App\Domain\Ticket\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\MasterScope;
use App\Models\Status;
use App\Models\Ticket;

/**
 * Basic ticket state machine until the Workflow Engine exists (ADR-057).
 *
 * Transitions use stable status codes (seeded by the SystemSeeder; names are
 * free to change). Every lifecycle step is a named operation with one target
 * status, so ChangeTicketStatus — the only status write path — and the UI
 * (contextual actions, never a free status dropdown) share one definition.
 * Phase 5 can replace this class with a versioned workflow.
 */
class TicketLifecycle
{
    /**
     * @var array<string, list<string>>
     */
    public const array TRANSITIONS = [
        'WAITING_ASSIGNMENT' => ['ASSIGNED', 'NEED_INFO', 'REJECTED', 'CANCELLED'],
        'ASSIGNED' => ['IN_PROGRESS', 'NEED_INFO', 'CANCELLED'],
        'IN_PROGRESS' => ['NEED_INFO', 'IN_REVIEW', 'CANCELLED'],
        'NEED_INFO' => ['CANCELLED'], // + back to the stored resume status
        'IN_REVIEW' => ['RESOLVED', 'REVISION', 'NEED_INFO'],
        'REVISION' => ['IN_PROGRESS', 'IN_REVIEW'],
        'RESOLVED' => ['CLOSED', 'REOPENED'],
        'CLOSED' => ['REOPENED'],
        'REOPENED' => ['ASSIGNED', 'IN_PROGRESS'],
        'REJECTED' => [],
        'CANCELLED' => [],
    ];

    /**
     * Operation => target status code.
     *
     * @var array<string, string>
     */
    public const array OPERATIONS = [
        'assign' => 'ASSIGNED',
        'start' => 'IN_PROGRESS',
        'request_information' => 'NEED_INFO',
        'send_to_review' => 'IN_REVIEW',
        'request_revision' => 'REVISION',
        'resolve' => 'RESOLVED',
        'close' => 'CLOSED',
        'reopen' => 'REOPENED',
        'reject' => 'REJECTED',
        'cancel' => 'CANCELLED',
    ];

    /**
     * Policy ability required per operation.
     *
     * @var array<string, string>
     */
    public const array ABILITIES = [
        'start' => 'work',
        'send_to_review' => 'work',
        'request_information' => 'requestInformation',
        'provide_information' => 'provideInformation',
        'request_revision' => 'review',
        'resolve' => 'review',
        'close' => 'close',
        'reopen' => 'close',
        'reject' => 'reject',
        'cancel' => 'cancel',
    ];

    /**
     * Status resolved by code, cached per request.
     *
     * @var array<string, Status|null>
     */
    private array $byCode = [];

    public function allows(Status $from, Status $to): bool
    {
        if ($from->id === $to->id || $from->scope !== MasterScope::Ticket || $to->scope !== MasterScope::Ticket) {
            return false;
        }

        if (array_key_exists($from->code, self::TRANSITIONS)) {
            return in_array($to->code, self::TRANSITIONS[$from->code], true);
        }

        return ! $from->is_terminal && ! $to->is_initial;
    }

    /**
     * Target status of an operation (must exist and be active).
     */
    public function target(string $operation): Status
    {
        $code = self::OPERATIONS[$operation] ?? throw new BusinessRuleException('Aksi ticket tidak dikenal.');

        $status = $this->byCode[$code] ??= Status::query()->forScope(MasterScope::Ticket)->where('code', $code)->first();

        if ($status === null || ! $status->is_active) {
            throw new BusinessRuleException('Status ticket untuk aksi ini belum dikonfigurasi atau nonaktif. Hubungi administrator.');
        }

        return $status;
    }

    /**
     * Whether an operation is possible from the ticket's current status.
     * Requires the status (and resumeStatus) relations.
     */
    public function canPerform(Ticket $ticket, string $operation): bool
    {
        $from = $ticket->status;

        if ($operation === 'provide_information') {
            return $from->is_blocked && $ticket->resume_status_id !== null;
        }

        if (! array_key_exists($operation, self::OPERATIONS)) {
            return false;
        }

        $code = self::OPERATIONS[$operation];
        $to = $this->byCode[$code] ??= Status::query()->forScope(MasterScope::Ticket)->where('code', $code)->first();

        return $to !== null && $to->is_active && $this->allows($from, $to);
    }

    /**
     * Operations available from the current status (before authorization).
     *
     * @return list<string>
     */
    public function operations(Ticket $ticket): array
    {
        $operations = array_keys(self::ABILITIES);

        return array_values(array_filter($operations, fn (string $operation): bool => $this->canPerform($ticket, $operation)));
    }

    /**
     * Whether the status is the target of an operation (e.g. the revision
     * status), so views never compare status names or codes themselves.
     */
    public function isStatusOf(string $operation, Status $status): bool
    {
        return isset(self::OPERATIONS[$operation]) && $status->code === self::OPERATIONS[$operation];
    }

    public function label(string $operation, ?Status $from = null): string
    {
        return match ($operation) {
            'start' => $from !== null && $from->counts_as_started ? 'Continue Work' : 'Start Work',
            'request_information' => 'Request Information',
            'provide_information' => 'Provide Information',
            'send_to_review' => 'Send to Review',
            'request_revision' => 'Request Revision',
            'resolve' => 'Resolve',
            'close' => 'Close Ticket',
            'reopen' => 'Reopen',
            'reject' => 'Reject Ticket',
            'cancel' => 'Cancel Ticket',
            default => ucfirst(str_replace('_', ' ', $operation)),
        };
    }

    public function rejectionMessage(Status $from, Status $to): string
    {
        return 'Status Ticket tidak dapat diubah dari '.$from->name.' ke '.$to->name.'.';
    }
}
