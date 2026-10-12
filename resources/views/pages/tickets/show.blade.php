<?php

use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ChangeTicketDeadline;
use App\Domain\Ticket\Actions\ChangeTicketPriority;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Ticket\Actions\ManageTicketRelations;
use App\Domain\Ticket\Actions\TransferTicket;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Handlers\TicketTransitionHandler;
use App\Domain\Workflow\Support\TransitionRules;
use App\Enums\CommentKind;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithWorkflowTransitions;
use App\Models\WorkflowTransition;
use App\Models\WorkflowTransitionHistory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Comment;
use App\Models\DepartmentMembership;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketRejectionReason;
use App\Models\TicketRelation;
use App\Models\TicketRelationType;
use App\Models\TicketResolution;
use App\Models\TicketTransferReason;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use WithFeedback, WithWorkflowTransitions;

    #[Locked]
    public int $ticketId;

    /** Text-based lifecycle step currently open in the step modal. */
    #[Locked]
    public ?string $stepOperation = null;

    /** Workflow transition behind the open domain modal (step, resolve, reject). */
    #[Locked]
    public ?int $domainTransitionId = null;

    public string $stepMessage = '';

    public ?int $picUserId = null;

    public ?int $collaboratorToAdd = null;

    public ?int $transferDepartmentId = null;

    public string $transferReasonId = '';

    public string $transferNotes = '';

    public string $rejectReasonId = '';

    public string $rejectNotes = '';

    public string $resolutionId = '';

    public string $resolutionNotes = '';

    public ?int $primaryTicketId = null;

    public string $priorityId = '';

    public string $dueDate = '';

    public string $deadlineReason = '';

    public ?int $relatedTicketId = null;

    public string $relationTypeId = '';

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);
        $this->ticketId = $ticket->id;
    }

    #[On('ticket-updated')]
    public function refreshTicket(): void
    {
        unset($this->ticket, $this->can, $this->actions, $this->lastRequest, $this->relations, $this->pendingTransition);
    }

    #[Computed]
    public function ticket(): Ticket
    {
        return Ticket::query()
            ->with([
                'status',
                'resumeStatus',
                'priority',
                'type:id,name,color',
                'category:id,name',
                'subcategory:id,name',
                'source:id,name',
                'client:id,name',
                'project.status',
                'task:id,number,title,project_id',
                'currentDepartment:id,name',
                'requester.primaryMembership.department:id,name',
                'creator:id,name',
                'acceptor:id,name',
                'resolution:id,name,color',
                'resolver:id,name',
                'rejectionReason:id,name',
                'picAssignment.user:id,name,username',
                'collaboratorAssignments.user:id,name,username',
                'transfers.fromDepartment:id,name',
                'transfers.toDepartment:id,name',
                'transfers.reason:id,name',
                'transfers.transferrer:id,name',
            ])
            ->findOrFail($this->ticketId);
    }

    /**
     * Abilities of the current user (policy + lifecycle state). Final
     * tickets offer no changes except reopen, also for the Super Admin.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function can(): array
    {
        $user = auth()->user();
        $ticket = $this->ticket;
        $active = $ticket->isActive();
        $final = $ticket->status->is_terminal;

        return [
            'update' => ! $final && $user->can('update', $ticket),
            'accept' => $active && ! $ticket->isAccepted() && $user->can('accept', $ticket),
            'assign' => $active && $user->can('assign', $ticket),
            'transfer' => $active && $user->can('transfer', $ticket),
            'priority' => $active && $user->can('changePriority', $ticket),
            'deadline' => $active && $user->can('manageDeadline', $ticket),
            'relations' => ! $final && $user->can('update', $ticket),
            'project' => $ticket->project !== null && $user->can('view', $ticket->project),
            'task' => $ticket->task !== null && $user->can('view', $ticket->task),
        ];
    }

    /**
     * Lifecycle steps valid now AND allowed for the user, with one primary.
     *
     * @return array{primary: array{key: string, label: string}|null, more: list<array{key: string, label: string}>}
     */
    #[Computed]
    public function actions(): array
    {
        $ticket = $this->ticket;
        $steps = collect();

        if ($this->can['accept']) {
            $steps->put('accept', 'Accept');
        }

        if ($this->can['assign'] && $ticket->picAssignment === null) {
            $steps->put('assign', 'Assign PIC');
        }

        foreach ($this->availableTransitions() as $transition) {
            $key = $transition->operation === 'move' || $steps->has($transition->operation) ? 't'.$transition->id : $transition->operation;
            $steps->put($key, $transition->action_label.($transition->requiresApproval() ? ' (approval)' : ''));
        }

        $primaryKey = collect(['accept', 'assign', 'provide_information', 'start', 'send_to_review', 'resolve', 'close'])
            ->first(fn (string $key): bool => $steps->has($key));

        $more = $steps->except($primaryKey ?? '')
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
            ->values()
            ->all();

        return [
            'primary' => $primaryKey ? ['key' => $primaryKey, 'label' => (string) $steps->get($primaryKey)] : null,
            'more' => $more,
        ];
    }

    /**
     * Latest message of a lifecycle step, for the callouts.
     */
    #[Computed]
    public function lastRequest(): ?Comment
    {
        $ticket = $this->ticket;
        $lastStep = WorkflowTransitionHistory::query()
            ->where('subject_type', 'ticket')
            ->where('subject_id', $ticket->id)
            ->with('transition:id,operation')
            ->latest('id')
            ->first();

        $kind = match (true) {
            $ticket->status->is_blocked => CommentKind::InformationRequest,
            $lastStep?->transition?->operation === 'request_revision' => CommentKind::RevisionRequest,
            default => null,
        };

        return $kind === null ? null : $ticket->comments()->where('kind', $kind->value)->with('user:id,name')->latest('id')->first();
    }

    public function isRevisionRequested(): bool
    {
        return ! $this->ticket->status->is_blocked && $this->lastRequest?->kind === CommentKind::RevisionRequest;
    }

    protected function workflowRecord(): Model
    {
        return $this->ticket;
    }

    protected function afterWorkflowTransition(): void
    {
        $this->refreshTicket();
        $this->dispatch('ticket-updated');
    }

    /**
     * The transition behind a domain operation from the current status, or
     * a friendly error when the workflow does not offer it now.
     */
    private function transitionFor(string $operation): WorkflowTransition
    {
        $handler = app(TicketTransitionHandler::class);
        $transition = $this->outgoingTransition(fn (WorkflowTransition $candidate): bool => $candidate->operation === $operation && $handler->isAvailable($this->ticket, $candidate));

        if ($transition === null) {
            throw new BusinessRuleException('Status Ticket tidak dapat diubah dari '.$this->ticket->status->name.' dengan aksi ini.');
        }

        abort_unless(app(TransitionRules::class)->canPerform(auth()->user(), $this->ticket, $transition), 403);

        return $transition;
    }

    /**
     * @return Collection<int, array{id: int, ticket: Ticket, type: string, visible: bool}>
     */
    #[Computed]
    public function relations(): Collection
    {
        $user = auth()->user();
        $visibleIds = Ticket::query()->visibleTo($user)->select('id');

        $outgoing = TicketRelation::query()->where('ticket_id', $this->ticketId)->with(['relatedTicket.status', 'type:id,name'])->get()
            ->map(fn (TicketRelation $relation): array => ['id' => $relation->id, 'ticket' => $relation->relatedTicket, 'type' => $relation->type->name]);
        $incoming = TicketRelation::query()->where('related_ticket_id', $this->ticketId)->with(['ticket.status', 'type:id,name'])->get()
            ->map(fn (TicketRelation $relation): array => ['id' => $relation->id, 'ticket' => $relation->ticket, 'type' => $relation->type->name.' (dari)']);

        $all = $outgoing->concat($incoming);
        $visible = Ticket::query()->whereIn('id', $all->pluck('ticket.id'))->whereIn('id', $visibleIds)->pluck('id')->all();

        return $all->map(fn (array $row): array => [...$row, 'visible' => in_array($row['ticket']->id, $visible, true)])->values();
    }

    /**
     * Active members of the current department (PIC/collaborator candidates).
     *
     * @return list<int>
     */
    #[Computed]
    public function departmentMemberIds(): array
    {
        return array_map(intval(...), DepartmentMembership::query()->where('department_id', $this->ticket->current_department_id)->whereNull('ended_at')->pluck('user_id')->all());
    }

    /**
     * @return array{resolutions: Collection<int, TicketResolution>, transferReasons: Collection<int, TicketTransferReason>, rejectionReasons: Collection<int, TicketRejectionReason>, priorities: Collection<int, Priority>, relationTypes: Collection<int, TicketRelationType>}
     */
    #[Computed]
    public function options(): array
    {
        return [
            'resolutions' => TicketResolution::query()->active()->orderBy('sort_order')->get(['id', 'name', 'requires_related_ticket']),
            'transferReasons' => TicketTransferReason::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'rejectionReasons' => TicketRejectionReason::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Ticket)->active()->orderBy('level')->get(['id', 'name']),
            'relationTypes' => TicketRelationType::query()->active()->orderBy('sort_order')->get(['id', 'name']),
        ];
    }

    public function resolutionNeedsPrimary(): bool
    {
        return (bool) $this->options['resolutions']->firstWhere('id', (int) $this->resolutionId)?->requires_related_ticket;
    }

    /**
     * Entry point for every lifecycle button: authorizes, then runs the step
     * directly or opens the modal that collects what the step needs.
     */
    public function perform(string $key, AcceptTicket $accept): void
    {
        $ticket = $this->ticket;

        if ($key === 'accept') {
            $this->authorize('accept', $ticket);
            $accept->handle($ticket, auth()->user());
            $this->done('Ticket berhasil diterima.');

            return;
        }

        if ($key === 'assign') {
            $this->authorize('assign', $ticket);
            $this->openPic();

            return;
        }

        if (preg_match('/^t(\d+)$/', $key, $matches) === 1) {
            $this->startTransition((int) $matches[1]);

            return;
        }

        abort_unless(in_array($key, ['start', 'close', 'resolve', 'reject', 'request_information', 'provide_information', 'send_to_review', 'request_revision', 'reopen', 'cancel'], true), 404);

        $this->authorize('view', $ticket);
        $transition = $this->transitionFor($key);

        if ($transition->requiresApproval() && ! in_array($key, ['resolve', 'reject'], true)) {
            $this->startTransition($transition->id);

            return;
        }

        $this->domainTransitionId = $transition->id;

        switch ($key) {
            case 'start':
            case 'close':
                $this->runTransition($transition->id);

                return;
            case 'resolve':
                $this->reset('resolutionId', 'resolutionNotes', 'primaryTicketId');
                $this->resetValidation();
                Flux::modal('ticket-resolve')->show();

                return;
            case 'reject':
                $this->reset('rejectReasonId', 'rejectNotes');
                $this->resetValidation();
                Flux::modal('ticket-reject')->show();

                return;
            default:
                $this->stepOperation = $key;
                $this->stepMessage = '';
                $this->resetValidation();
                Flux::modal('ticket-step')->show();
        }
    }

    /**
     * @return array{title: string, text: string, label: string, required: bool, button: string, danger: bool}
     */
    public function stepConfig(): array
    {
        return match ($this->stepOperation) {
            'request_information' => ['title' => 'Request Information', 'text' => 'Ticket menunggu jawaban requester, lalu kembali ke status saat ini.', 'label' => 'Informasi yang dibutuhkan', 'required' => true, 'button' => 'Send Request', 'danger' => false],
            'provide_information' => ['title' => 'Provide Information', 'text' => 'Jawaban Anda dikirim ke percakapan dan ticket kembali dikerjakan.', 'label' => 'Informasi', 'required' => true, 'button' => 'Send Information', 'danger' => false],
            'send_to_review' => ['title' => 'Send to Review', 'text' => 'Requester atau reviewer akan memeriksa hasil pekerjaan.', 'label' => 'Catatan untuk reviewer (opsional)', 'required' => false, 'button' => 'Send to Review', 'danger' => false],
            'request_revision' => ['title' => 'Request Revision', 'text' => 'Jelaskan apa yang masih perlu diperbaiki.', 'label' => 'Alasan revisi', 'required' => true, 'button' => 'Request Revision', 'danger' => false],
            'reopen' => ['title' => 'Reopen Ticket', 'text' => 'Riwayat penyelesaian sebelumnya tetap tercatat di Activity.', 'label' => 'Alasan membuka kembali', 'required' => true, 'button' => 'Reopen Ticket', 'danger' => false],
            'cancel' => ['title' => 'Cancel Ticket', 'text' => 'Ticket yang dibatalkan tidak dapat dikerjakan lagi.', 'label' => 'Alasan pembatalan', 'required' => true, 'button' => 'Cancel Ticket', 'danger' => true],
            default => ['title' => '', 'text' => '', 'label' => '', 'required' => false, 'button' => 'Save', 'danger' => false],
        };
    }

    public function submitStep(): void
    {
        $operation = (string) $this->stepOperation;
        abort_unless(in_array($operation, ['request_information', 'provide_information', 'send_to_review', 'request_revision', 'reopen', 'cancel'], true), 404);
        $transition = $this->transitionFor($operation);

        $required = $this->stepConfig()['required'];
        $this->validate(['stepMessage' => [$required ? 'required' : 'nullable', 'string', 'max:5000']], ['stepMessage.required' => 'Bagian ini wajib diisi.'], ['stepMessage' => 'Message']);

        $input = in_array($operation, ['request_revision', 'reopen', 'cancel'], true)
            ? ['reason' => $this->stepMessage]
            : ['comment' => $this->stepMessage];

        $this->runTransition($transition->id, array_filter($input, fn ($value) => $value !== ''));

        Flux::modal('ticket-step')->close();
        $this->stepOperation = null;
    }

    public function resolve(): void
    {
        $this->authorize('view', $this->ticket);
        $transition = $this->transitionFor('resolve');
        $this->validate([
            'resolutionId' => ['required', 'integer'],
            'resolutionNotes' => ['required', 'string', 'max:5000'],
            'primaryTicketId' => [$this->resolutionNeedsPrimary() ? 'required' : 'nullable', 'integer'],
        ], ['primaryTicketId.required' => 'Pilih ticket utama untuk resolusi ini.'], ['resolutionId' => 'Resolution', 'resolutionNotes' => 'Resolution notes']);

        $this->runTransition($transition->id, array_filter([
            'resolution_id' => (int) $this->resolutionId,
            'notes' => $this->resolutionNotes,
            'primary_ticket_id' => $this->primaryTicketId,
        ], fn ($value) => $value !== null));

        Flux::modal('ticket-resolve')->close();
    }

    public function reject(): void
    {
        $this->authorize('view', $this->ticket);
        $transition = $this->transitionFor('reject');
        $this->validate(['rejectReasonId' => ['required', 'integer'], 'rejectNotes' => ['required', 'string', 'max:5000']], attributes: ['rejectReasonId' => 'Reason', 'rejectNotes' => 'Notes']);

        $this->runTransition($transition->id, ['rejection_reason_id' => (int) $this->rejectReasonId, 'notes' => $this->rejectNotes, 'reason' => $this->rejectNotes]);

        Flux::modal('ticket-reject')->close();
    }

    public function openPic(): void
    {
        abort_unless($this->can['assign'], 403);
        $this->picUserId = null;
        $this->resetValidation();
        Flux::modal('ticket-pic')->show();
    }

    public function savePic(ManageTicketAssignments $assignments): void
    {
        $ticket = $this->ticket;
        $this->authorize('assign', $ticket);
        $this->validate(['picUserId' => ['required', 'integer']], ['picUserId.required' => 'PIC wajib dipilih.']);

        $ticket->picAssignment
            ? $assignments->changePic($ticket, (int) $this->picUserId, auth()->user())
            : $assignments->assign($ticket, (int) $this->picUserId, auth()->user());

        Flux::modal('ticket-pic')->close();
        $this->done('Ticket berhasil ditugaskan ke '.User::query()->whereKey($this->picUserId)->value('name').'.');
    }

    public function updatedCollaboratorToAdd(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $this->authorize('assign', $this->ticket);
        $this->collaboratorToAdd = null;

        app(ManageTicketAssignments::class)->addCollaborator($this->ticket, $userId, auth()->user());

        $this->done('Kolaborator ditambahkan.');
    }

    public function removeCollaborator(int $assignmentId, ManageTicketAssignments $assignments): void
    {
        $this->authorize('assign', $this->ticket);

        $assignments->removeCollaborator($this->ticket, $assignmentId, auth()->user());

        $this->done('Kolaborator dikeluarkan. Riwayatnya tetap tersimpan.');
    }

    public function openTransfer(): void
    {
        abort_unless($this->can['transfer'], 403);
        $this->reset('transferDepartmentId', 'transferReasonId', 'transferNotes');
        $this->resetValidation();
        Flux::modal('ticket-transfer')->show();
    }

    public function transfer(TransferTicket $transferTicket): void
    {
        $this->authorize('transfer', $this->ticket);
        $this->validate([
            'transferDepartmentId' => ['required', 'integer'],
            'transferReasonId' => ['required', 'integer'],
            'transferNotes' => ['nullable', 'string', 'max:2000'],
        ], ['transferDepartmentId.required' => 'Pilih department tujuan.', 'transferReasonId.required' => 'Alasan transfer wajib dipilih.']);

        $ticket = $transferTicket->handle($this->ticket, (int) $this->transferDepartmentId, (int) $this->transferReasonId, $this->transferNotes ?: null, auth()->user());

        Flux::modal('ticket-transfer')->close();
        $name = $ticket->loadMissing('currentDepartment:id,name')->currentDepartment->name;

        if (! auth()->user()->can('view', $ticket->refresh())) {
            $this->notifyAfterRedirect('success', 'Ticket berhasil dipindahkan ke '.$name.'.');
            $this->redirectRoute('tickets.inbox', navigate: true);

            return;
        }

        $this->done('Ticket berhasil dipindahkan ke '.$name.'.');
    }

    public function openPriority(): void
    {
        abort_unless($this->can['priority'], 403);
        $this->priorityId = (string) $this->ticket->priority_id;
        Flux::modal('ticket-priority')->show();
    }

    public function savePriority(ChangeTicketPriority $changePriority): void
    {
        $this->authorize('changePriority', $this->ticket);
        $this->validate(['priorityId' => ['required', 'integer']]);

        $changePriority->handle($this->ticket, (int) $this->priorityId, auth()->user());

        Flux::modal('ticket-priority')->close();
        $this->done('Priority ticket diperbarui.');
    }

    public function openDeadline(): void
    {
        abort_unless($this->can['deadline'], 403);
        $this->dueDate = (string) $this->ticket->current_due_at?->toDateString();
        $this->deadlineReason = '';
        $this->resetValidation();
        Flux::modal('ticket-deadline')->show();
    }

    public function saveDeadline(ChangeTicketDeadline $changeDeadline): void
    {
        $this->authorize('manageDeadline', $this->ticket);
        $this->validate([
            'dueDate' => ['nullable', 'date'],
            'deadlineReason' => [$this->ticket->planned_due_at !== null ? 'required' : 'nullable', 'string', 'max:1000'],
        ], attributes: ['dueDate' => 'Deadline', 'deadlineReason' => 'Reason']);

        $changeDeadline->handle($this->ticket, $this->dueDate ?: null, $this->deadlineReason ?: null, auth()->user());

        Flux::modal('ticket-deadline')->close();
        $this->done('Deadline ticket diperbarui.');
    }

    public function openRelation(): void
    {
        abort_unless($this->can['relations'], 403);
        $this->reset('relatedTicketId', 'relationTypeId');
        $this->resetValidation();
        Flux::modal('ticket-relation')->show();
    }

    public function addRelation(ManageTicketRelations $relations): void
    {
        $this->authorize('update', $this->ticket);
        $this->validate(['relatedTicketId' => ['required', 'integer'], 'relationTypeId' => ['required', 'integer']], ['relatedTicketId.required' => 'Pilih ticket yang akan dihubungkan.']);

        $relations->add($this->ticket, (int) $this->relatedTicketId, (int) $this->relationTypeId, auth()->user());

        Flux::modal('ticket-relation')->close();
        $this->done('Ticket berhasil dihubungkan.');
    }

    public function removeRelation(int $relationId, ManageTicketRelations $relations): void
    {
        $this->authorize('update', $this->ticket);

        $relations->remove($this->ticket, $relationId, auth()->user());

        $this->done('Hubungan ticket dilepas.');
    }

    private function done(string $message): void
    {
        $this->notify('success', $message);
        $this->refreshTicket();
        $this->dispatch('ticket-updated');
    }

    public function render()
    {
        return $this->view()->title($this->ticket->number.' · '.$this->ticket->title);
    }
}; ?>

@php($ticket = $this->ticket)
@php($can = $this->can)
@php($actions = $this->actions)
@php($dangerKeys = ['cancel', 'reject'])

<div class="mx-auto w-full max-w-6xl">
    {{-- Header --}}
    <div class="mb-6">
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('tickets.index')" wire:navigate>Tickets</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $ticket->number }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="font-mono text-sm text-zinc-500">{{ $ticket->number }}</div>
                <flux:heading size="xl" level="1" class="mt-0.5 [overflow-wrap:anywhere]">{{ $ticket->title }}</flux:heading>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.master-badge :master="$ticket->status" />
                    <x-ui.master-badge :master="$ticket->priority" />
                    @if ($ticket->isOverdue())
                        <flux:badge size="sm" color="red" icon="clock">Overdue</flux:badge>
                    @endif
                    @if ($ticket->isActive() && ! $ticket->isAccepted())
                        <flux:badge size="sm" color="amber">Belum diterima department</flux:badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($actions['primary'])
                    @php($primary = $actions['primary'])
                    @if ($primary['key'] === 'close')
                        <flux:button variant="primary" icon="check-circle" x-on:click="hwConfirm({ title: 'Tutup ticket?', text: 'Ticket dianggap selesai dan final. Masih dapat dibuka kembali bila diperlukan.', confirmText: 'Close Ticket' }).then(ok => ok && $wire.perform('close'))">{{ $primary['label'] }}</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="perform('{{ $primary['key'] }}')" wire:loading.attr="disabled" wire:target="perform">{{ $primary['label'] }}</flux:button>
                    @endif
                @endif
                @if ($actions['more'] !== [] || $can['update'] || $can['transfer'] || $can['priority'] || $can['deadline'])
                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon:trailing="chevron-down">More</flux:button>
                        <flux:menu>
                            @foreach ($actions['more'] as $action)
                                @if ($action['key'] === 'close')
                                    <flux:menu.item icon="check-circle" wire:key="act-close" x-on:click="hwConfirm({ title: 'Tutup ticket?', text: 'Ticket dianggap selesai dan final.', confirmText: 'Close Ticket' }).then(ok => ok && $wire.perform('close'))">{{ $action['label'] }}</flux:menu.item>
                                @else
                                    <flux:menu.item :icon="in_array($action['key'], $dangerKeys, true) ? 'x-circle' : 'arrow-right-circle'" :variant="in_array($action['key'], $dangerKeys, true) ? 'danger' : null"
                                        wire:key="act-{{ $action['key'] }}" wire:click="perform('{{ $action['key'] }}')">{{ $action['label'] }}</flux:menu.item>
                                @endif
                            @endforeach
                            @if ($actions['more'] !== [] && ($can['update'] || $can['transfer'] || $can['priority'] || $can['deadline']))
                                <flux:menu.separator />
                            @endif
                            @if ($can['transfer'])
                                <flux:menu.item icon="arrows-right-left" wire:click="openTransfer">Transfer Department</flux:menu.item>
                            @endif
                            @if ($can['priority'])
                                <flux:menu.item icon="signal" wire:click="openPriority">Change priority</flux:menu.item>
                            @endif
                            @if ($can['deadline'])
                                <flux:menu.item icon="calendar-days" wire:click="openDeadline">Change deadline</flux:menu.item>
                            @endif
                            @if ($can['update'])
                                <flux:menu.item icon="pencil-square" :href="route('tickets.edit', $ticket)" wire:navigate>Edit details</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>
    </div>

    {{-- Callouts: what happens next --}}
    @if ($ticket->status->is_blocked)
        <flux:callout variant="warning" icon="chat-bubble-left-ellipsis" class="mb-6" heading="Waiting for Information">
            <flux:callout.text>
                @if ($this->lastRequest)
                    {{ $this->lastRequest->user->name }} meminta informasi tambahan:
                    <span class="mt-1 block whitespace-pre-line font-medium">“{{ $this->lastRequest->body }}”</span>
                @else
                    Ticket menunggu informasi tambahan dari requester.
                @endif
            </flux:callout.text>
            @if (collect($actions['more'])->contains('key', 'provide_information') || ($actions['primary']['key'] ?? null) === 'provide_information')
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="perform('provide_information')">Provide Information</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @elseif ($this->isRevisionRequested())
        <flux:callout variant="warning" icon="arrow-uturn-left" class="mb-6" heading="Revision Requested">
            <flux:callout.text>
                @if ($this->lastRequest)
                    <span class="whitespace-pre-line">{{ $this->lastRequest->body }}</span>
                    <span class="mt-1 block text-xs">— {{ $this->lastRequest->user->name }}</span>
                @endif
            </flux:callout.text>
            @if (($actions['primary']['key'] ?? null) === 'start')
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="perform('start')">Continue Work</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @elseif ($ticket->status->counts_as_completed && ! $ticket->status->is_terminal)
        <flux:callout variant="success" icon="check-badge" class="mb-6" heading="Resolution Ready">
            <flux:callout.text>
                Resolution: <span class="font-medium">{{ $ticket->resolution?->name }}</span>
                @if ($ticket->resolution_notes)
                    <span class="mt-1 block whitespace-pre-line">Catatan: {{ $ticket->resolution_notes }}</span>
                @endif
                <span class="mt-1 block text-xs">Diselesaikan oleh {{ $ticket->resolver->name ?? 'System' }} · {{ $ticket->resolved_at?->translatedFormat('d M Y H:i') }}</span>
            </flux:callout.text>
        </flux:callout>
    @elseif ($ticket->status->is_terminal)
        <flux:callout :variant="$ticket->status->counts_as_completed ? 'secondary' : 'danger'" :icon="$ticket->status->counts_as_completed ? 'lock-closed' : 'x-circle'" class="mb-6" :heading="'Ticket '.$ticket->status->name">
            <flux:callout.text>
                @if ($ticket->rejectionReason)
                    Ditolak: <span class="font-medium">{{ $ticket->rejectionReason->name }}</span>
                    @if ($ticket->rejection_notes)
                        <span class="mt-1 block whitespace-pre-line">{{ $ticket->rejection_notes }}</span>
                    @endif
                @elseif ($ticket->status->counts_as_completed)
                    Resolution: {{ $ticket->resolution?->name ?? '—' }}. Ticket sudah final{{ $ticket->closed_at ? ' sejak '.$ticket->closed_at->translatedFormat('d M Y H:i') : '' }}.
                @else
                    Ticket ini tidak lagi dikerjakan. Alasannya tercatat di Activity.
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Properties: first on mobile (compact), right column on desktop --}}
        <aside class="h-fit min-w-0 rounded-xl border border-zinc-200 bg-white lg:order-last">
            <dl class="divide-y divide-zinc-100 px-5">
                <div class="grid grid-cols-2 gap-4 py-3">
                    <div>
                        <dt class="text-xs text-zinc-500">Department</dt>
                        <dd class="mt-1 text-sm font-medium text-zinc-900">{{ $ticket->currentDepartment->name }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center justify-between text-xs text-zinc-500">
                            Priority
                            @if ($can['priority'])
                                <flux:button size="xs" variant="ghost" wire:click="openPriority" inset="top bottom">Change</flux:button>
                            @endif
                        </dt>
                        <dd class="mt-1"><x-ui.master-badge :master="$ticket->priority" /></dd>
                    </div>
                </div>
                <div class="py-3">
                    <dt class="text-xs text-zinc-500">Main PIC</dt>
                    <dd class="mt-1 flex items-center justify-between gap-2">
                        @if ($ticket->picAssignment)
                            <span class="flex min-w-0 items-center gap-2">
                                <flux:avatar size="xs" :name="$ticket->picAssignment->user->name" :initials="$ticket->picAssignment->user->initials()" />
                                <span class="truncate text-sm font-medium text-zinc-900">{{ $ticket->picAssignment->user->name }}</span>
                            </span>
                        @else
                            <span class="text-sm text-zinc-400">Belum ditentukan</span>
                        @endif
                        @if ($can['assign'])
                            <flux:button size="xs" variant="ghost" wire:click="openPic">{{ $ticket->picAssignment ? 'Change' : 'Assign' }}</flux:button>
                        @endif
                    </dd>
                </div>
                <div class="py-3">
                    <dt class="text-xs text-zinc-500">Collaborators</dt>
                    <dd class="mt-1 space-y-1">
                        @forelse ($ticket->collaboratorAssignments as $assignment)
                            <div wire:key="tcol-{{ $assignment->id }}" class="flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-2">
                                    <flux:avatar size="xs" :name="$assignment->user->name" :initials="$assignment->user->initials()" />
                                    <span class="truncate text-sm text-zinc-800">{{ $assignment->user->name }}</span>
                                </span>
                                @if ($can['assign'])
                                    <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="Remove {{ $assignment->user->name }}"
                                        x-on:click="hwConfirm({ title: 'Keluarkan kolaborator?', text: @js($assignment->user->name.' tidak lagi menjadi kolaborator ticket ini. Riwayatnya tetap tersimpan.'), confirmText: 'Keluarkan', danger: true }).then(ok => ok && $wire.removeCollaborator({{ $assignment->id }}))" />
                                @endif
                            </div>
                        @empty
                            <span class="text-sm text-zinc-400">Belum ada kolaborator</span>
                        @endforelse
                        @if ($can['assign'])
                            <div class="pt-1">
                                <livewire:user-picker wire:model.live="collaboratorToAdd" label="" placeholder="Tambah kolaborator..." :only="$this->departmentMemberIds"
                                    empty-hint="Kolaborator berasal dari department saat ini."
                                    :exclude="array_values(array_filter([$ticket->picAssignment?->user_id, ...$ticket->collaboratorAssignments->pluck('user_id')->all()]))"
                                    :key="'tcollab-'.$ticket->collaboratorAssignments->count().'-'.$ticket->picAssignment?->user_id.'-'.$ticket->current_department_id" />
                            </div>
                        @endif
                    </dd>
                </div>
                <div class="py-3">
                    <dt class="text-xs text-zinc-500">Requested by</dt>
                    <dd class="mt-1 text-sm">
                        <span class="font-medium text-zinc-900">{{ $ticket->requester->name }}</span>
                        @if ($ticket->requester->primaryMembership)
                            <span class="block text-xs text-zinc-500">{{ $ticket->requester->primaryMembership->department->name }}</span>
                        @endif
                        @if ($ticket->created_by !== $ticket->requester_user_id && $ticket->creator)
                            <span class="block text-xs text-zinc-500">Dibuat oleh {{ $ticket->creator->name }}</span>
                        @endif
                    </dd>
                </div>
                <div class="py-3">
                    <dt class="flex items-center justify-between text-xs text-zinc-500">
                        Deadline
                        @if ($can['deadline'])
                            <flux:button size="xs" variant="ghost" wire:click="openDeadline" inset="top bottom">Change</flux:button>
                        @endif
                    </dt>
                    <dd class="mt-1 text-sm">
                        @if ($ticket->current_due_at)
                            <x-ui.deadline :date="$ticket->current_due_at" :done="! $ticket->isActive()" />
                            @if ($ticket->planned_due_at && ! $ticket->planned_due_at->isSameDay($ticket->current_due_at))
                                <div class="text-xs text-zinc-500">Rencana awal {{ $ticket->planned_due_at->translatedFormat('d M Y') }}</div>
                            @endif
                        @else
                            <span class="text-zinc-400">Tanpa deadline</span>
                        @endif
                    </dd>
                </div>
                @if ($ticket->client)
                    <x-ui.detail-item label="Client" class="py-3 sm:flex-col sm:gap-1">{{ $ticket->client->name }}</x-ui.detail-item>
                @endif
                <x-ui.detail-item label="Type / Category" class="py-3 sm:flex-col sm:gap-1">{{ collect([$ticket->type->name, $ticket->category->name, $ticket->subcategory?->name])->filter()->join(' · ') }}</x-ui.detail-item>
                <x-ui.detail-item label="Source" class="py-3 sm:flex-col sm:gap-1">{{ $ticket->source?->name }}</x-ui.detail-item>
                <x-ui.detail-item label="Created" class="py-3 sm:flex-col sm:gap-1">
                    {{ $ticket->created_at?->translatedFormat('d M Y H:i') }}
                    @if ($ticket->accepted_at)
                        <span class="block text-xs text-zinc-500">Diterima {{ $ticket->acceptor->name ?? '' }} · {{ $ticket->accepted_at->translatedFormat('d M Y H:i') }}</span>
                    @endif
                </x-ui.detail-item>
            </dl>
        </aside>

        {{-- Main --}}
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <flux:card>
                <flux:heading size="lg">Description</flux:heading>
                <flux:text class="mt-2 whitespace-pre-line [overflow-wrap:anywhere]">{{ $ticket->description }}</flux:text>
            </flux:card>

            <livewire:approvals.progress subject-type="ticket" :subject-id="$ticket->id" wire:key="approval-progress-{{ $ticket->id }}" />

            <livewire:tickets.conversation :ticket-id="$ticket->id" wire:key="conversation-{{ $ticket->id }}" />

            <livewire:tickets.attachments :ticket-id="$ticket->id" wire:key="attachments-{{ $ticket->id }}" />

            {{-- Related work --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">Related</flux:heading>
                    @if ($can['relations'])
                        <flux:button size="sm" variant="ghost" icon="link" wire:click="openRelation">Link ticket</flux:button>
                    @endif
                </div>
                <dl class="divide-y divide-zinc-100">
                    <x-ui.detail-item label="Project">
                        @if ($ticket->project && $can['project'])
                            <a href="{{ route('projects.show', $ticket->project) }}" wire:navigate class="font-medium hover:underline">{{ $ticket->project->name }}</a>
                        @elseif ($ticket->project)
                            <span class="text-zinc-500">Project terbatas (Anda tidak memiliki akses)</span>
                        @endif
                    </x-ui.detail-item>
                    <x-ui.detail-item label="Task">
                        @if ($ticket->task && $can['task'])
                            <a href="{{ route('tasks.show', $ticket->task) }}" wire:navigate class="font-medium hover:underline">{{ $ticket->task->number }} — {{ $ticket->task->title }}</a>
                        @elseif ($ticket->task)
                            <span class="text-zinc-500">Task terbatas (Anda tidak memiliki akses)</span>
                        @endif
                    </x-ui.detail-item>
                    <div class="py-3">
                        <dt class="text-sm text-zinc-500">Related Tickets</dt>
                        <dd class="mt-2 space-y-2">
                            @forelse ($this->relations as $relation)
                                <div wire:key="rel-{{ $relation['id'] }}" class="flex items-center justify-between gap-3">
                                    @if ($relation['visible'])
                                        <a href="{{ route('tickets.show', $relation['ticket']) }}" wire:navigate class="min-w-0">
                                            <span class="font-mono text-xs text-zinc-500">{{ $relation['ticket']->number }} · {{ $relation['type'] }}</span>
                                            <span class="block truncate text-sm font-medium text-zinc-800 hover:underline">{{ $relation['ticket']->title }}</span>
                                        </a>
                                    @else
                                        <span class="min-w-0 text-sm text-zinc-500">{{ $relation['ticket']->number }} · {{ $relation['type'] }} (terbatas)</span>
                                    @endif
                                    @if ($can['relations'])
                                        <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="Unlink"
                                            x-on:click="hwConfirm({ title: 'Lepas hubungan ticket?', confirmText: 'Lepas', danger: true }).then(ok => ok && $wire.removeRelation({{ $relation['id'] }}))" />
                                    @endif
                                </div>
                            @empty
                                <span class="text-sm text-zinc-400">Belum ada ticket terkait</span>
                            @endforelse
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Routing history --}}
            @if ($ticket->transfers->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white p-5">
                    <flux:heading size="lg" class="mb-3">Department history</flux:heading>
                    <ol class="space-y-3">
                        <li class="text-sm text-zinc-700">
                            <span class="font-medium">{{ $ticket->transfers->first()->fromDepartment->name }}</span> menerima ticket pertama kali · {{ $ticket->created_at?->translatedFormat('d M Y H:i') }}
                        </li>
                        @foreach ($ticket->transfers as $transfer)
                            <li wire:key="tr-{{ $transfer->id }}" class="text-sm text-zinc-700">
                                <span class="font-medium">{{ $transfer->fromDepartment->name }} → {{ $transfer->toDepartment->name }}</span>
                                <span class="block text-xs text-zinc-500">{{ $transfer->reason->name }} · oleh {{ $transfer->transferrer->name }} · {{ $transfer->transferred_at->translatedFormat('d M Y H:i') }}</span>
                                @if ($transfer->notes)
                                    <span class="block text-xs text-zinc-500">“{{ $transfer->notes }}”</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            <livewire:tickets.activity :ticket-id="$ticket->id" wire:key="activity-{{ $ticket->id }}" />
        </div>
    </div>

    {{-- Modals --}}
    @include('partials.workflow.transition-modal')

    <flux:modal name="ticket-step" class="w-full max-w-md">
        @php($step = $this->stepConfig())
        <form wire:submit="submitStep" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $step['title'] }}</flux:heading>
                <flux:text class="mt-1">{{ $step['text'] }}</flux:text>
            </div>
            <flux:textarea wire:model="stepMessage" :label="$step['label']" :badge="$step['required'] ? 'Required' : null" rows="4" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" :variant="$step['danger'] ? 'danger' : 'primary'" wire:loading.attr="disabled" wire:target="submitStep">{{ $step['button'] }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="ticket-resolve" class="w-full max-w-md">
        <form wire:submit="resolve" class="space-y-6">
            <div>
                <flux:heading size="lg">Resolve Ticket</flux:heading>
                <flux:text class="mt-1">Requester dapat menutup ticket atau membukanya kembali setelah ini.</flux:text>
            </div>
            <flux:select wire:model.live="resolutionId" label="Resolution" badge="Required" placeholder="Pilih resolution">
                @foreach ($this->options['resolutions'] as $option)
                    <flux:select.option :value="$option->id" wire:key="res-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($this->resolutionNeedsPrimary())
                <div>
                    <livewire:tickets.picker wire:model="primaryTicketId" label="Primary ticket" :required="true" :exclude="[$ticket->id]" key="primary-ticket-picker" />
                    <flux:error name="primaryTicketId" />
                </div>
            @endif
            <flux:textarea wire:model="resolutionNotes" label="Resolution notes" badge="Required" rows="4" placeholder="Apa yang dilakukan dan hasilnya." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="resolve">Resolve</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="ticket-reject" class="w-full max-w-md">
        <form wire:submit="reject" class="space-y-6">
            <div>
                <flux:heading size="lg">Reject Ticket</flux:heading>
                <flux:text class="mt-1">Gunakan bila request tidak valid. Requester akan melihat alasannya.</flux:text>
            </div>
            <flux:select wire:model="rejectReasonId" label="Reason" badge="Required" placeholder="Pilih alasan">
                @foreach ($this->options['rejectionReasons'] as $option)
                    <flux:select.option :value="$option->id" wire:key="rr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="rejectNotes" label="Notes" badge="Required" rows="3" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="reject">Reject Ticket</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($can['assign'])
        <flux:modal name="ticket-pic" class="w-full max-w-md">
            <form wire:submit="savePic" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $ticket->picAssignment ? 'Change PIC' : 'Assign PIC' }}</flux:heading>
                    <flux:text class="mt-1">PIC harus anggota aktif {{ $ticket->currentDepartment->name }}. PIC sebelumnya tetap tercatat di riwayat.</flux:text>
                </div>
                <div>
                    <livewire:user-picker wire:model="picUserId" label="PIC" :required="true" :only="$this->departmentMemberIds"
                        :exclude="array_values(array_filter([$ticket->picAssignment?->user_id]))"
                        empty-hint="Tidak ada anggota department yang cocok. Transfer ticket bila perlu ditangani department lain."
                        :key="'ticket-pic-'.$ticket->current_department_id.'-'.$ticket->picAssignment?->user_id" />
                    <flux:error name="picUserId" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePic">Save PIC</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['transfer'])
        <flux:modal name="ticket-transfer" class="w-full max-w-md">
            <form wire:submit="transfer" class="space-y-6">
                <div>
                    <flux:heading size="lg">Transfer Ticket</flux:heading>
                    <flux:text class="mt-1">PIC dan kolaborator saat ini dilepas; ticket masuk ke inbox department tujuan sebagai ticket baru.</flux:text>
                </div>
                <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm">
                    <span class="text-zinc-500">From</span>
                    <div class="font-medium text-zinc-900">{{ $ticket->currentDepartment->name }}</div>
                </div>
                <div>
                    <livewire:department-picker wire:model="transferDepartmentId" label="To" :required="true" :exclude="[$ticket->current_department_id]" :key="'transfer-dept-'.$ticket->current_department_id" />
                    <flux:error name="transferDepartmentId" />
                </div>
                <flux:select wire:model="transferReasonId" label="Reason" badge="Required" placeholder="Pilih alasan">
                    @foreach ($this->options['transferReasons'] as $option)
                        <flux:select.option :value="$option->id" wire:key="trr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="transferNotes" label="Notes" rows="3" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="transfer">Transfer Ticket</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['priority'])
        <flux:modal name="ticket-priority" class="w-full max-w-sm">
            <form wire:submit="savePriority" class="space-y-6">
                <flux:heading size="lg">Change priority</flux:heading>
                <flux:select wire:model="priorityId" label="Priority">
                    @foreach ($this->options['priorities'] as $option)
                        <flux:select.option :value="$option->id" wire:key="pri-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['deadline'])
        <flux:modal name="ticket-deadline" class="w-full max-w-md">
            <form wire:submit="saveDeadline" class="space-y-6">
                <div>
                    <flux:heading size="lg">Change deadline</flux:heading>
                    <flux:text class="mt-1">{{ $ticket->planned_due_at ? 'Rencana awal tidak berubah. Perubahan tercatat beserta alasannya.' : 'Deadline pertama menjadi rencana awal ticket.' }}</flux:text>
                </div>
                <flux:input wire:model="dueDate" type="date" label="Deadline" />
                @if ($ticket->planned_due_at)
                    <flux:textarea wire:model="deadlineReason" label="Reason" badge="Required" rows="3" />
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Update deadline</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['relations'])
        <flux:modal name="ticket-relation" class="w-full max-w-md">
            <form wire:submit="addRelation" class="space-y-6">
                <flux:heading size="lg">Link ticket</flux:heading>
                <div>
                    <livewire:tickets.picker wire:model="relatedTicketId" label="Ticket" :required="true" :exclude="[$ticket->id]" key="relation-ticket-picker" />
                    <flux:error name="relatedTicketId" />
                </div>
                <flux:select wire:model="relationTypeId" label="Relation" badge="Required" placeholder="Pilih jenis hubungan">
                    @foreach ($this->options['relationTypes'] as $option)
                        <flux:select.option :value="$option->id" wire:key="rt-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Link</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
