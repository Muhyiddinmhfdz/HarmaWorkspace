<?php

namespace App\Domain\Ticket\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Invariants shared by ticket write actions.
 */
class TicketGuard
{
    /**
     * Final tickets (closed, rejected, cancelled) are read-only.
     */
    public function ensureNotFinal(Ticket $ticket): void
    {
        $ticket->loadMissing('status');

        if ($ticket->status->is_terminal) {
            throw new BusinessRuleException('Ticket sudah berakhir ('.$ticket->status->name.') dan tidak dapat diubah. Buka kembali (reopen) bila masih perlu ditangani.');
        }
    }

    /**
     * Ownership changes (assignment, transfer, acceptance) need an active
     * ticket: not resolved and not final.
     */
    public function ensureActive(Ticket $ticket): void
    {
        $this->ensureNotFinal($ticket);

        if ($ticket->status->counts_as_completed) {
            throw new BusinessRuleException('Ticket sudah di-resolve. Buka kembali (reopen) bila masih perlu ditangani.');
        }
    }

    public function department(int $departmentId, ?int $currentId = null): Department
    {
        $department = Department::query()->find($departmentId);

        if ($department === null || (! $department->is_active && $departmentId !== $currentId)) {
            throw new BusinessRuleException('Department tujuan harus department aktif.');
        }

        return $department;
    }

    /**
     * PIC and collaborators come from the ticket's current department, so
     * ownership stays clear; cross-department work goes through a transfer.
     */
    public function departmentMember(int $userId, Department $department): User
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new BusinessRuleException('User yang dipilih harus user aktif.');
        }

        if (! $user->activeMemberships()->where('department_id', $department->id)->exists()) {
            throw new BusinessRuleException($user->name.' bukan anggota aktif Department '.$department->name.'. Transfer Ticket bila pekerjaan perlu ditangani department lain.');
        }

        return $user;
    }

    /**
     * Validates the optional project/task relation from the actor's point of
     * view: both must be visible to the actor, the task must belong to the
     * selected project, a task's project is used when no project is given,
     * and archived/finished projects do not accept new tickets.
     *
     * @return array{0: Project|null, 1: Task|null}
     */
    public function work(?int $projectId, ?int $taskId, User $actor, ?Ticket $current = null): array
    {
        $task = null;

        if ($taskId !== null) {
            $task = Task::query()->with('project.status')->find($taskId);

            if ($task === null || ($taskId !== $current?->task_id && ! $task->isVisibleTo($actor))) {
                throw new BusinessRuleException('Task yang dipilih tidak ditemukan atau tidak dapat Anda akses.');
            }

            if ($projectId !== null && $task->project_id !== $projectId) {
                throw new BusinessRuleException('Task '.$task->number.' tidak berasal dari Project yang dipilih.');
            }

            $projectId ??= $task->project_id;
        }

        $project = null;

        if ($projectId !== null) {
            $project = Project::query()->with('status')->find($projectId);

            if ($project === null || ($projectId !== $current?->project_id && ! $project->isVisibleTo($actor))) {
                throw new BusinessRuleException('Project yang dipilih tidak ditemukan atau tidak dapat Anda akses.');
            }

            if ($projectId !== $current?->project_id && ($project->isArchived() || $project->status->is_terminal)) {
                throw new BusinessRuleException('Project '.$project->name.' sudah berakhir atau diarsipkan dan tidak menerima Ticket baru. Buat Ticket standalone bila masih ada kebutuhan support.');
            }
        }

        return [$project, $task];
    }

    public function subcategory(?int $subcategoryId, int $categoryId, ?int $currentId = null): ?TicketSubcategory
    {
        if ($subcategoryId === null) {
            return null;
        }

        $subcategory = TicketSubcategory::query()->find($subcategoryId);

        if ($subcategory === null || $subcategory->ticket_category_id !== $categoryId) {
            throw new BusinessRuleException('Subcategory tidak sesuai dengan Category yang dipilih.');
        }

        if (! $subcategory->is_active && $subcategoryId !== $currentId) {
            throw new BusinessRuleException('Subcategory nonaktif tidak dapat dipilih.');
        }

        return $subcategory;
    }

    /**
     * New selections must be active; an unchanged value may stay inactive.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    public function master(string $model, ?int $id, string $label, ?int $currentId = null, bool $required = false): ?Model
    {
        if ($id === null) {
            if ($required) {
                throw new BusinessRuleException($label.' wajib dipilih.');
            }

            return null;
        }

        $record = $model::query()->find($id);

        if ($record === null || (! $record->getAttribute('is_active') && $id !== $currentId)) {
            throw new BusinessRuleException($label.' tidak ditemukan atau sedang nonaktif.');
        }

        return $record;
    }
}
