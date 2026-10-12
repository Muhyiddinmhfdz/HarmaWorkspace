<?php

namespace Tests\Concerns;

use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\CreateTicket;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketResolution;
use App\Models\TicketType;
use App\Models\User;

/**
 * Builds tickets and department members through the real domain actions.
 */
trait CreatesTickets
{
    use CreatesTasks;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTicket(array $overrides = [], ?User $actor = null): Ticket
    {
        $actor ??= $this->staff();

        return app(CreateTicket::class)->handle([
            'title' => 'Error transaksi POS',
            'description' => 'Saat pembayaran muncul error 500.',
            'ticket_type_id' => $this->ticketType()->id,
            'ticket_category_id' => $this->ticketCategory()->id,
            'priority_id' => $this->ticketPriority('NORMAL')->id,
            'current_department_id' => $overrides['current_department_id'] ?? Department::factory()->create()->id,
            ...$overrides,
        ], $actor)->refresh();
    }

    protected function ticketType(string $code = 'BUG'): TicketType
    {
        return TicketType::query()->firstOrCreate(['code' => $code], ['name' => ucfirst(strtolower($code))]);
    }

    protected function ticketCategory(string $code = 'APP'): TicketCategory
    {
        return TicketCategory::query()->firstOrCreate(['code' => $code], ['name' => 'Application '.$code]);
    }

    protected function ticketStatus(string $code): Status
    {
        return Status::query()->forScope(MasterScope::Ticket)->where('code', $code)->firstOrFail();
    }

    protected function ticketPriority(string $code): Priority
    {
        return Priority::query()->forScope(MasterScope::Ticket)->where('code', $code)->firstOrFail();
    }

    protected function resolution(string $code = 'FIXED'): TicketResolution
    {
        return TicketResolution::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Active member (optionally leader) of a department with the Staff role.
     */
    protected function member(Department $department, bool $leader = false, ?User $user = null): User
    {
        $user ??= $this->staff();

        DepartmentMembership::query()->create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'is_primary' => ! $user->activeMemberships()->exists(),
            'is_leader' => $leader,
            'joined_at' => today(),
        ]);

        return $user->fresh();
    }

    /**
     * A ticket accepted and assigned to $pic (a member of its department).
     */
    protected function assignedTicket(Department $department, User $pic, array $overrides = []): Ticket
    {
        $actor = isset($overrides['project_id']) || isset($overrides['task_id']) ? $this->superAdmin() : null;
        $ticket = $this->createTicket(['current_department_id' => $department->id, ...$overrides], $actor);
        app(ManageTicketAssignments::class)->assign($ticket, $pic->id, $this->superAdmin());

        return $ticket->refresh();
    }

    /**
     * Assigned → In Progress → In Review.
     */
    protected function ticketInReview(Department $department, User $pic, array $overrides = []): Ticket
    {
        $ticket = $this->assignedTicket($department, $pic, $overrides);
        $status = app(ChangeTicketStatus::class);
        $status->start($ticket, $pic);
        $status->sendToReview($ticket->refresh(), $pic);

        return $ticket->refresh();
    }

    protected function acceptTicket(Ticket $ticket, ?User $actor = null): Ticket
    {
        return app(AcceptTicket::class)->handle($ticket, $actor ?? $this->superAdmin())->refresh();
    }
}
