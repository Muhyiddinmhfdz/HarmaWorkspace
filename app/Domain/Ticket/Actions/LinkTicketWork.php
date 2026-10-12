<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Link (or unlink) the related project and task. Both must be visible to
 * the actor and consistent (the task belongs to the project). Linking never
 * changes the project or task lifecycle (ADR-059).
 */
class LinkTicketWork
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function handle(Ticket $ticket, ?int $projectId, ?int $taskId, User $actor): Ticket
    {
        $this->guard->ensureNotFinal($ticket);
        [$project, $task] = $this->guard->work($projectId, $taskId, $actor, $ticket);

        $projectChanged = $ticket->project_id !== $project?->id;
        $taskChanged = $ticket->task_id !== $task?->id;

        if (! $projectChanged && ! $taskChanged) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $project, $task, $projectChanged, $taskChanged, $actor): Ticket {
            $ticket->loadMissing(['project:id,name', 'task:id,number,title']);
            $oldProject = $ticket->project?->name;
            $oldTask = $ticket->task?->number;

            $ticket->forceFill(['project_id' => $project?->id, 'task_id' => $task?->id, 'updated_by' => $actor->id])->save();

            if ($projectChanged) {
                $this->audit->record($ticket, 'ticket.project_linked', $project ? 'Mengaitkan ticket ke project '.$project->name : 'Melepas ticket dari project '.$oldProject,
                    old: ['project' => $oldProject], new: ['project' => $project?->name]);
            }

            if ($taskChanged) {
                $this->audit->record($ticket, 'ticket.task_linked', $task ? 'Mengaitkan ticket ke task '.$task->number : 'Melepas ticket dari task '.$oldTask,
                    old: ['task' => $oldTask], new: ['task' => $task?->number]);
            }

            return $ticket->unsetRelation('project')->unsetRelation('task');
        });
    }
}
