<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Numbering\NumberGenerator;
use App\Domain\System\Settings\SettingKey;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Enums\MasterScope;
use App\Enums\WorkflowEntity;
use App\Models\Client;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSource;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Create a ticket in the target department's inbox (initial status, not yet
 * accepted, no PIC). The creator is the requester unless another internal
 * user is given (architecture ready for "on behalf of", no UI yet).
 * The deadline becomes the baseline (planned = current).
 */
class CreateTicket
{
    public function __construct(
        private NumberGenerator $numbers,
        private ScopedMasterGuard $masters,
        private TicketGuard $guard,
        private TicketAudit $audit,
        private AddTicketAttachment $attachments,
        private WorkflowRegistry $workflows,
        private WorkflowGate $workflow,
    ) {}

    /**
     * @param  array{title: string, description: string, workflow_id?: int|null, ticket_type_id: int, ticket_category_id: int, priority_id: int, current_department_id: int, ticket_subcategory_id?: int|null, ticket_source_id?: int|null, client_id?: int|null, project_id?: int|null, task_id?: int|null, due_date?: string|null, requester_user_id?: int|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function handle(array $data, User $actor, array $files = []): Ticket
    {
        $requester = filled($data['requester_user_id'] ?? null) ? User::query()->find((int) $data['requester_user_id']) : $actor;

        if ($requester === null || ! $requester->is_active) {
            throw new BusinessRuleException('Requester harus user internal yang aktif.');
        }

        $version = $this->workflows->versionForNew(WorkflowEntity::Ticket, filled($data['workflow_id'] ?? null) ? (int) $data['workflow_id'] : null);
        $status = $this->workflows->initialState($version)->status;
        $priority = $this->masters->priority((int) $data['priority_id'], MasterScope::Ticket);
        $department = $this->guard->department((int) $data['current_department_id']);
        $this->guard->master(TicketType::class, (int) $data['ticket_type_id'], 'Ticket type', required: true);
        $category = $this->guard->master(TicketCategory::class, (int) $data['ticket_category_id'], 'Category', required: true);
        $subcategory = $this->guard->subcategory($this->id($data['ticket_subcategory_id'] ?? null), (int) $category?->getKey());
        $this->guard->master(TicketSource::class, $this->id($data['ticket_source_id'] ?? null), 'Source');
        $client = $this->guard->master(Client::class, $this->id($data['client_id'] ?? null), 'Client');
        [$project, $task] = $this->guard->work($this->id($data['project_id'] ?? null), $this->id($data['task_id'] ?? null), $actor);

        $due = filled($data['due_date'] ?? null) ? Carbon::parse((string) $data['due_date'])->endOfDay() : null;

        return DB::transaction(function () use ($data, $actor, $requester, $status, $priority, $department, $category, $subcategory, $client, $project, $task, $due, $files, $version): Ticket {
            $ticket = new Ticket([
                'title' => $data['title'],
                'description' => $data['description'],
                'ticket_type_id' => (int) $data['ticket_type_id'],
                'ticket_category_id' => (int) $category?->getKey(),
                'ticket_subcategory_id' => $subcategory?->id,
                'ticket_source_id' => $this->id($data['ticket_source_id'] ?? null),
                'client_id' => $client?->getKey(),
            ]);

            $ticket->forceFill([
                'number' => $this->numbers->next('ticket', SettingKey::TicketNumberPrefix),
                'requester_user_id' => $requester->id,
                'project_id' => $project?->id,
                'task_id' => $task?->id,
                'status_id' => $status->id,
                'workflow_version_id' => $version->id,
                'priority_id' => $priority->id,
                'current_department_id' => $department->id,
                'planned_due_at' => $due,
                'current_due_at' => $due,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record($ticket, 'ticket.created',
                'Membuat ticket '.$ticket->number.' '.$ticket->title.' untuk '.$department->name.($priority->level >= $this->topPriorityLevel() ? ' (prioritas '.$priority->name.')' : ''),
                new: [
                    'title' => $ticket->title,
                    'department' => $department->name,
                    'priority' => $priority->name,
                    'requester' => $requester->name,
                    'due_at' => $due?->toDateString(),
                ],
                properties: ['priority_level' => $priority->level],
            );

            $this->workflow->record($ticket, null, null, $status, $actor, null, ['event' => 'created']);

            foreach ($files as $file) {
                $this->attachments->store($ticket, $file, $actor, audit: false);
            }

            return $ticket;
        });
    }

    private function id(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    private function topPriorityLevel(): int
    {
        return (int) Priority::query()->forScope(MasterScope::Ticket)->active()->max('level');
    }
}
