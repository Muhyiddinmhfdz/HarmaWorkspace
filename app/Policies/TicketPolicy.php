<?php

namespace App\Policies;

use App\Enums\TicketAssignmentRole;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;

/**
 * Ticket authorization = visibility (Ticket::scopeVisibleTo) + a global
 * permission or a contextual role. No global "reviewer" or department roles
 * exist (ADR-062):
 *
 * - leader of the CURRENT department (manager): accept, reject, assign,
 *   collaborators, transfer, priority, deadline, review, close, cancel;
 * - Main PIC: start/continue work, send to review, request information;
 * - requester (or creator): provide information, review the result
 *   (resolve / request revision) unless they are the PIC, close, reopen,
 *   cancel their own request, edit the description;
 * - anyone who can see an active ticket: comment and attach files.
 *
 * Lifecycle validity (which step is possible now) is decided by
 * TicketLifecycle; final tickets are read-only.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ticket.view') || $user->can('ticket.view-all');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('ticket.create');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->editable($ticket) && $ticket->isVisibleTo($user)
            && ($this->isManager($user, $ticket) || $this->isRequester($user, $ticket) || $user->can('ticket.update'));
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->editable($ticket) && $ticket->isVisibleTo($user);
    }

    public function accept(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isManager($user, $ticket) || $user->can('ticket.manage-inbox'));
    }

    public function reject(User $user, Ticket $ticket): bool
    {
        return $this->accept($user, $ticket);
    }

    public function changePriority(User $user, Ticket $ticket): bool
    {
        return $this->accept($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isManager($user, $ticket) || $user->can('ticket.assign'));
    }

    public function transfer(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isManager($user, $ticket) || $user->can('ticket.transfer'));
    }

    public function manageDeadline(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isManager($user, $ticket) || $user->can('ticket.manage-deadline'));
    }

    public function linkWork(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    /**
     * Start/continue work and send to review.
     */
    public function work(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isPic($user, $ticket) || $this->isManager($user, $ticket) || $user->can('ticket.manage-inbox'));
    }

    public function requestInformation(User $user, Ticket $ticket): bool
    {
        return $this->work($user, $ticket) || $this->review($user, $ticket);
    }

    public function provideInformation(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isRequester($user, $ticket) || $this->isManager($user, $ticket) || $user->can('ticket.update'));
    }

    /**
     * Resolve or request revision. The Main PIC cannot approve their own work
     * unless they hold ticket.review (or lead the department).
     */
    public function review(User $user, Ticket $ticket): bool
    {
        if (! $ticket->isVisibleTo($user)) {
            return false;
        }

        if ($this->isManager($user, $ticket) || $user->can('ticket.review')) {
            return true;
        }

        return $this->isRequester($user, $ticket) && ! $this->isPic($user, $ticket);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isRequester($user, $ticket) || $this->isManager($user, $ticket) || $user->can('ticket.close'));
    }

    public function cancel(User $user, Ticket $ticket): bool
    {
        return $ticket->isVisibleTo($user) && ($this->isRequester($user, $ticket) || $this->isManager($user, $ticket) || $user->can('ticket.cancel'));
    }

    /**
     * Leader of the ticket's current department (contextual).
     */
    public function isManager(User $user, Ticket $ticket): bool
    {
        return $user->leadsDepartment($ticket->current_department_id);
    }

    public function isRequester(User $user, Ticket $ticket): bool
    {
        return $ticket->requester_user_id === $user->id || $ticket->created_by === $user->id;
    }

    public function isPic(User $user, Ticket $ticket): bool
    {
        return $ticket->activeAssignments()->where('user_id', $user->id)->where('role', TicketAssignmentRole::Pic->value)->exists();
    }

    /**
     * Department Inbox of a department: active members see it, leaders and
     * ticket.manage-inbox holders manage it, view-all sees every inbox.
     */
    public function viewInbox(User $user, Department $department): bool
    {
        return $user->is_active && ($user->can('ticket.view-all') || $user->can('ticket.manage-inbox')
            || ($user->can('ticket.view') && $user->activeMemberships()->where('department_id', $department->id)->exists()));
    }

    private function editable(Ticket $ticket): bool
    {
        return ! $ticket->loadMissing('status')->status->is_terminal;
    }
}
