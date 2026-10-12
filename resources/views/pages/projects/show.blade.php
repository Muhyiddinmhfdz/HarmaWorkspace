<?php

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\ChangeProjectProgressMode;
use App\Domain\Project\Actions\ChangeProjectSchedule;
use App\Domain\Project\Actions\ChangeProjectVisibility;
use App\Domain\Project\Actions\UpdateProjectHealth;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Project\Support\ProjectProgressCalculator;
use App\Domain\Project\Support\VisibilityGuard;
use App\Enums\ProgressMode;
use App\Enums\ProjectVisibility;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithWorkflowTransitions;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectMilestone;
use App\Models\Status;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    use WithFeedback, WithWorkflowTransitions;

    /** Primary workspace tabs (Phase 6: at most four). */
    public const array TABS = ['overview' => 'Overview', 'tasks' => 'Tasks', 'reports' => 'Reports', 'activity' => 'Activity'];

    /** Secondary sections, reachable from the "More" tab menu. */
    public const array MORE_TABS = ['milestones' => 'Milestones', 'team' => 'Team'];

    /** Old tab keys kept working for bookmarks and links. */
    private const array TAB_ALIASES = ['tickets' => 'reports'];

    #[Locked]
    public int $projectId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // Modal forms
    public string $visibility = '';

    public string $progressMode = '';

    public int $progress = 0;

    public string $healthId = '';

    public string $healthNote = '';

    public string $scheduleStart = '';

    public string $scheduleEnd = '';

    public string $scheduleReason = '';

    public string $completedOn = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;

        $this->tab = self::TAB_ALIASES[$this->tab] ?? $this->tab;

        if (! array_key_exists($this->tab, self::TABS + self::MORE_TABS)) {
            $this->tab = 'overview';
        }
    }

    public function updatedTab(): void
    {
        $this->tab = self::TAB_ALIASES[$this->tab] ?? $this->tab;

        if (! array_key_exists($this->tab, self::TABS + self::MORE_TABS)) {
            $this->tab = 'overview';
        }
    }

    #[On('project-updated')]
    public function refreshProject(): void
    {
        unset($this->project, $this->can, $this->transitions, $this->upcomingMilestones, $this->lastScheduleChange, $this->taskSummary, $this->departmentProgress, $this->currentWork, $this->canCompleteNow, $this->reportSummary, $this->openIssues, $this->pendingTransition);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->with([
                'client:id,name',
                'category:id,name',
                'status',
                'priority',
                'health',
                'owner:id,name,username',
                'projectManager:id,name,username',
                'departments' => fn ($query) => $query->orderByDesc('is_lead')->with(['department:id,name', 'leadUser:id,name']),
            ])
            ->findOrFail($this->projectId);
    }

    /**
     * Abilities resolved once per render.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function can(): array
    {
        $user = auth()->user();
        $project = $this->project;
        $editable = ! $project->isArchived();

        return [
            'update' => $editable && $user->can('update', $project),
            'visibility' => $editable && $user->can('changeVisibility', $project),
            'schedule' => $editable && $user->can('manageSchedule', $project),
            'progress' => $editable && $user->can('manageProgress', $project),
            'complete' => $editable && $user->can('complete', $project),
            'archive' => $user->can('archive', $project),
            // Archived/finished projects take no new work, also for the Super Admin bypass.
            'createTask' => $editable && ! $project->status->is_terminal && $user->can('create', [Task::class, $project]),
            'createReport' => $editable && ! $project->status->is_terminal && $user->can('create', \App\Models\Ticket::class),
        ];
    }

    /**
     * @return Collection<int, ProjectMilestone>
     */
    #[Computed]
    public function upcomingMilestones(): Collection
    {
        return $this->project->milestones()->whereNull('actual_date')->orderBy('current_due_date')->limit(5)->get();
    }

    /**
     * Task counters from status flags (never names).
     *
     * @return array{total: int, done: int, in_progress: int, blocked: int, overdue: int, counted: int}
     */
    #[Computed]
    public function taskSummary(): array
    {
        $row = Task::query()
            ->where('tasks.project_id', $this->projectId)
            ->join('statuses', 'statuses.id', '=', 'tasks.status_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(statuses.counts_as_completed) as done')
            ->selectRaw('SUM(statuses.counts_as_started AND NOT statuses.is_terminal AND NOT statuses.is_blocked) as in_progress')
            ->selectRaw('SUM(statuses.is_blocked) as blocked')
            ->selectRaw('SUM(NOT statuses.is_terminal AND tasks.current_end < ?) as overdue', [today()->toDateString()])
            ->selectRaw('SUM(NOT statuses.is_terminal OR statuses.counts_as_completed) as counted')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'done' => (int) ($row->done ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'counted' => (int) ($row->counted ?? 0),
        ];
    }

    /**
     * Open reports (tickets) of the project grouped by type, limited to the
     * reports the user may see (ticket visibility is independent from project
     * visibility). One aggregate query; types come from the master.
     *
     * @return array{open: int, types: list<array{name: string, color: string|null, count: int}>}|null
     */
    #[Computed]
    public function reportSummary(): ?array
    {
        $user = auth()->user();

        if (! $user->can('viewAny', \App\Models\Ticket::class)) {
            return null;
        }

        $queue = app(\App\Domain\Ticket\Support\TicketQueue::class);
        $query = $queue->base($user)->where('tickets.project_id', $this->projectId);
        $queue->apply($query, 'open', $user);

        $types = $query->join('ticket_types as rt', 'rt.id', '=', 'tickets.ticket_type_id')
            ->toBase()
            ->selectRaw('rt.id, rt.name, rt.color, COUNT(*) as aggregate')
            ->groupBy('rt.id', 'rt.name', 'rt.color')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'color' => $row->color, 'count' => (int) $row->aggregate])
            ->all();

        return ['open' => array_sum(array_column($types, 'count')), 'types' => $types];
    }

    /**
     * Most important open reports: highest priority, waiting ones, most
     * recently updated. Visible reports only.
     *
     * @return Collection<int, \App\Models\Ticket>
     */
    #[Computed]
    public function openIssues(): Collection
    {
        $user = auth()->user();

        if (! $user->can('viewAny', \App\Models\Ticket::class)) {
            return collect();
        }

        $queue = app(\App\Domain\Ticket\Support\TicketQueue::class);
        $query = $queue->base($user)->where('tickets.project_id', $this->projectId);
        $queue->apply($query, 'open', $user);

        return $query->join('priorities as oi_priority', 'oi_priority.id', '=', 'tickets.priority_id')
            ->select('tickets.*')
            ->with(['status', 'priority', 'type:id,name,color', 'currentDepartment:id,name', 'picAssignment.user:id,name'])
            ->orderByDesc('oi_priority.level')
            ->orderByDesc($queue::STATUS.'.is_blocked')
            ->orderByDesc('tickets.updated_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, array{department_id: int, name: string, is_lead: bool, progress: int, tasks: int}>
     */
    #[Computed]
    public function departmentProgress(): Collection
    {
        return app(ProjectProgressCalculator::class)->byDepartment($this->project);
    }

    /**
     * Current work: the few active tasks that matter most (blocked first,
     * then priority, deadline and recent updates). Project tasks follow the
     * project's visibility, which the page already authorized.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function currentWork(): Collection
    {
        return Task::query()
            ->where('tasks.project_id', $this->projectId)
            ->open()
            ->join('statuses as cw_status', 'cw_status.id', '=', 'tasks.status_id')
            ->join('priorities as cw_priority', 'cw_priority.id', '=', 'tasks.priority_id')
            ->select('tasks.*')
            ->with(['status', 'priority', 'picAssignment.user:id,name'])
            ->orderByDesc('cw_status.is_blocked')
            ->orderByDesc('cw_priority.level')
            ->orderByRaw('tasks.current_end IS NULL ASC')
            ->orderBy('tasks.current_end')
            ->orderByDesc('tasks.updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function lastScheduleChange(): ?\App\Models\ScheduleChange
    {
        return $this->project->scheduleChanges()->with('changedBy:id,name')->latest('id')->first();
    }

    /**
     * Lifecycle actions available from the current status (no free status
     * dropdown). Completion is offered separately through CompleteProject.
     *
     * @return list<array{status: Status, label: string, danger: bool}>
     */
    #[Computed]
    public function transitions(): array
    {
        if ($this->project->isArchived()) {
            return [];
        }

        return array_values(array_map(fn (WorkflowTransition $transition): array => [
            'id' => $transition->id,
            'status' => $transition->toState->status,
            'label' => $transition->action_label,
            'confirm' => $transition->confirmation_message,
            'approval' => $transition->requiresApproval(),
            'danger' => $transition->toState->status->is_terminal,
        ], array_filter($this->availableTransitions(), fn (WorkflowTransition $transition): bool => $transition->operation !== 'complete')));
    }

    #[Computed]
    public function canCompleteNow(): bool
    {
        return ! $this->project->isArchived() && $this->completeTransition() !== null;
    }

    private function completeTransition(): ?WorkflowTransition
    {
        return collect($this->availableTransitions())->first(fn (WorkflowTransition $transition): bool => $transition->operation === 'complete');
    }

    protected function workflowRecord(): Model
    {
        return $this->project;
    }

    protected function afterWorkflowTransition(): void
    {
        $this->refreshProject();
    }

    /**
     * @return Collection<int, ProjectHealthStatus>
     */
    #[Computed]
    public function healthOptions(): Collection
    {
        return ProjectHealthStatus::query()->active()->where('represents_completion', false)->orderBy('level')->get();
    }

    public function moveToStatus(int $statusId): void
    {
        $transition = $this->outgoingTransition(fn (WorkflowTransition $candidate): bool => $candidate->toState->status_id === $statusId && $candidate->operation !== 'complete');

        if ($transition === null) {
            $target = Status::query()->findOrFail($statusId);

            throw new BusinessRuleException('Status Project tidak dapat diubah dari '.$this->project->status->name.' ke '.$target->name.'.');
        }

        $this->startTransition($transition->id);
    }

    public function openProgressMode(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->progressMode = $this->project->progress_mode->value;
        Flux::modal('project-progress-mode')->show();
    }

    public function saveProgressMode(ChangeProjectProgressMode $changeMode): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['progressMode' => ['required', \Illuminate\Validation\Rule::enum(ProgressMode::class)]]);

        $mode = ProgressMode::from($this->progressMode);
        $changeMode->handle($this->project, $mode, auth()->user());

        Flux::modal('project-progress-mode')->close();
        $this->notify('success', $mode === ProgressMode::TaskWeighted ? 'Progress project kini dihitung otomatis dari Task.' : 'Progress project kini diisi manual.');
        $this->refreshProject();
    }

    public function openVisibility(): void
    {
        abort_unless($this->can['visibility'], 403);
        $this->visibility = $this->project->visibility->value;
        $this->resetValidation();
        Flux::modal('project-visibility')->show();
    }

    public function saveVisibility(ChangeProjectVisibility $changeVisibility): void
    {
        $this->validate(['visibility' => ['required', \Illuminate\Validation\Rule::enum(ProjectVisibility::class)]]);
        $target = ProjectVisibility::from($this->visibility);
        $this->authorize('changeVisibility', [$this->project, $target]);

        $changeVisibility->handle($this->project, $target, auth()->user());

        Flux::modal('project-visibility')->close();
        $this->notify('success', 'Visibility project diubah menjadi '.$target->label().'.');
        $this->refreshProject();
    }

    public function canSetVisibility(ProjectVisibility $visibility): bool
    {
        return app(VisibilityGuard::class)->canSet($visibility, auth()->user());
    }

    public function openProgress(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->progress = $this->project->progress_percentage;
        $this->resetValidation();
        Flux::modal('project-progress')->show();
    }

    public function saveProgress(UpdateProjectProgress $updateProgress): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $updateProgress->handle($this->project, $this->progress, auth()->user());

        Flux::modal('project-progress')->close();
        $this->notify('success', 'Progress project diperbarui menjadi '.$this->progress.'%.');
        $this->refreshProject();
    }

    public function openHealth(): void
    {
        abort_unless($this->can['progress'], 403);
        $this->healthId = (string) $this->project->health_status_id;
        $this->healthNote = '';
        $this->resetValidation();
        Flux::modal('project-health')->show();
    }

    public function saveHealth(UpdateProjectHealth $updateHealth): void
    {
        $this->authorize('manageProgress', $this->project);
        $this->validate(['healthId' => ['required', 'integer'], 'healthNote' => ['nullable', 'string', 'max:500']]);

        $updateHealth->handle($this->project, (int) $this->healthId, $this->healthNote ?: null, auth()->user());

        Flux::modal('project-health')->close();
        $this->notify('success', 'Project health diperbarui.');
        $this->refreshProject();
    }

    public function openSchedule(): void
    {
        abort_unless($this->can['schedule'], 403);
        $this->scheduleStart = $this->project->current_start->toDateString();
        $this->scheduleEnd = $this->project->current_end->toDateString();
        $this->scheduleReason = '';
        $this->resetValidation();
        Flux::modal('project-schedule')->show();
    }

    public function saveSchedule(ChangeProjectSchedule $changeSchedule): void
    {
        $this->authorize('manageSchedule', $this->project);
        $this->validate([
            'scheduleStart' => ['required', 'date'],
            'scheduleEnd' => ['required', 'date', 'after_or_equal:scheduleStart'],
            'scheduleReason' => ['required', 'string', 'min:5', 'max:1000'],
        ], attributes: ['scheduleStart' => 'Start date', 'scheduleEnd' => 'New deadline', 'scheduleReason' => 'Reason']);

        $changeSchedule->handle($this->project, $this->scheduleStart, $this->scheduleEnd, $this->scheduleReason, auth()->user());

        Flux::modal('project-schedule')->close();
        $this->notify('success', 'Jadwal project diperbarui.');
        $this->refreshProject();
    }

    public function openComplete(): void
    {
        abort_unless($this->canCompleteNow, 403);
        $this->completedOn = today()->toDateString();
        $this->resetValidation();
        Flux::modal('project-complete')->show();
    }

    public function complete(): void
    {
        $this->authorize('view', $this->project);
        $transition = $this->completeTransition();
        abort_if($transition === null, 403);
        $this->validate(['completedOn' => ['required', 'date', 'before_or_equal:today']], attributes: ['completedOn' => 'Completion date']);

        $outcome = $this->runTransition($transition->id, ['actual_end' => $this->completedOn]);

        Flux::modal('project-complete')->close();

        if (! $outcome->awaitsApproval()) {
            $this->notify('success', 'Project berhasil diselesaikan.');
        }
    }

    public function toggleArchive(ArchiveProject $archiveProject): void
    {
        $this->authorize('archive', $this->project);

        $project = $this->project;
        $project->isArchived() ? $archiveProject->restore($project, auth()->user()) : $archiveProject->archive($project, auth()->user());

        $this->notify('success', $project->isArchived() ? 'Project berhasil diarsipkan.' : 'Project berhasil dipulihkan dari arsip.');
        $this->refreshProject();
    }

    public function render()
    {
        return $this->view()->title($this->project->name);
    }
}; ?>

@php($project = $this->project)
@php($can = $this->can)

<div class="mx-auto w-full max-w-6xl">
    {{-- Header: what is this project, who leads it, how far, until when --}}
    <div class="mb-6">
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project->number }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="font-mono text-sm text-zinc-500">{{ $project->number }}</div>
                <flux:heading size="xl" level="1" class="mt-0.5 [overflow-wrap:anywhere]">{{ $project->name }}</flux:heading>
                <div class="mt-1 truncate text-sm text-zinc-500">
                    {{ $project->client->name ?? 'Internal Project' }}{{ ($lead = $project->departments->firstWhere('is_lead', true)) ? ' · '.$lead->department->name : '' }}
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.master-badge :master="$project->status" />
                    @if ($project->health && ! $project->health->is_default)
                        <x-ui.master-badge :master="$project->health" />
                    @endif
                    @if ($project->isArchived())
                        <flux:badge size="sm" color="zinc" icon="archive-box">Archived</flux:badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($can['createTask'])
                    <flux:button variant="primary" icon="plus" :href="route('tasks.create', ['project' => $project->id])" wire:navigate>Task</flux:button>
                @endif
                @if ($can['createReport'])
                    <flux:button icon="plus" :href="route('tickets.create', ['project' => $project->id])" wire:navigate>Report</flux:button>
                @endif
                @if (in_array(true, \Illuminate\Support\Arr::except($can, ['createTask', 'createReport']), true))
                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon="ellipsis-horizontal" aria-label="More actions" />
                        <flux:menu>
                            @if ($can['update'])
                                <flux:menu.item icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>Edit project</flux:menu.item>
                            @endif
                            @foreach ($this->transitions as $transition)
                                @if ($transition['danger'])
                                    <flux:menu.item icon="x-circle" variant="danger" wire:key="tr-{{ $transition['id'] }}"
                                        x-on:click="hwConfirm({ title: @js($transition['label'].'?'), text: @js($transition['confirm'] ?? 'Project akan berakhir dengan status '.$transition['status']->name.'. Data dan riwayat tetap tersimpan.'), confirmText: @js($transition['label']), cancelText: 'Kembali', danger: true }).then(ok => ok && $wire.startTransition({{ $transition['id'] }}))">
                                        {{ $transition['label'] }}
                                    </flux:menu.item>
                                @elseif ($transition['confirm'])
                                    <flux:menu.item icon="arrow-right-circle" wire:key="tr-{{ $transition['id'] }}"
                                        x-on:click="hwConfirm({ title: @js($transition['label'].'?'), text: @js($transition['confirm']), confirmText: @js($transition['label']) }).then(ok => ok && $wire.startTransition({{ $transition['id'] }}))">{{ $transition['label'] }}</flux:menu.item>
                                @else
                                    <flux:menu.item :icon="$transition['approval'] ? 'shield-check' : 'arrow-right-circle'" wire:key="tr-{{ $transition['id'] }}" wire:click="startTransition({{ $transition['id'] }})">{{ $transition['label'] }}@if ($transition['approval']) <span class="ms-1 text-xs text-zinc-400">(approval)</span>@endif</flux:menu.item>
                                @endif
                            @endforeach
                            @if ($this->canCompleteNow)
                                <flux:menu.item icon="check-badge" wire:click="openComplete">Complete project</flux:menu.item>
                            @endif
                            @if ($can['progress'] || $can['schedule'] || $can['visibility'])
                                <flux:menu.separator />
                            @endif
                            @if ($can['progress'] && $project->progress_mode === \App\Enums\ProgressMode::Manual)
                                <flux:menu.item icon="chart-bar" wire:click="openProgress">Update progress</flux:menu.item>
                            @endif
                            @if ($can['schedule'])
                                <flux:menu.item icon="calendar-days" wire:click="openSchedule">Change schedule</flux:menu.item>
                            @endif
                            @if ($can['progress'])
                                <flux:menu.item icon="heart" wire:click="openHealth">Update health</flux:menu.item>
                                <flux:menu.item icon="calculator" wire:click="openProgressMode">Progress calculation</flux:menu.item>
                            @endif
                            @if ($can['visibility'])
                                <flux:menu.item icon="eye" wire:click="openVisibility">Change visibility</flux:menu.item>
                            @endif
                            @if ($can['archive'] && $project->isArchived())
                                <flux:menu.separator />
                                <flux:menu.item icon="arrow-uturn-left"
                                    x-on:click="hwConfirm({ title: 'Pulihkan project dari arsip?', text: 'Project akan kembali tampil di daftar dan dapat diubah lagi.', confirmText: 'Pulihkan' }).then(ok => ok && $wire.toggleArchive())">
                                    Restore from archive
                                </flux:menu.item>
                            @elseif ($can['archive'] && $project->status->is_terminal)
                                <flux:menu.separator />
                                <flux:menu.item icon="archive-box" variant="danger"
                                    x-on:click="hwConfirm({ title: 'Arsipkan Project?', text: 'Project tidak akan tampil di daftar project aktif, tetapi seluruh data dan riwayat tetap tersimpan.', confirmText: 'Arsipkan', cancelText: 'Batal', danger: true }).then(ok => ok && $wire.toggleArchive())">
                                    Archive project
                                </flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        {{-- Key facts --}}
        <dl class="mt-5 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-zinc-200 bg-zinc-200 sm:grid-cols-3">
            <div class="col-span-2 bg-white px-4 py-3 sm:col-span-1">
                <dt class="flex items-center justify-between text-xs text-zinc-500">
                    Progress
                    <span>{{ $project->progress_mode === \App\Enums\ProgressMode::TaskWeighted ? 'Calculated from Tasks' : 'Manual' }}</span>
                </dt>
                <dd class="mt-1 flex items-center gap-3">
                    <span class="text-xl font-semibold tabular-nums text-zinc-900">{{ $project->progress_percentage }}%</span>
                    <x-ui.progress-bar :value="$project->progress_percentage" :show-label="false" class="flex-1" />
                </dd>
            </div>
            <div class="bg-white px-4 py-3">
                <dt class="text-xs text-zinc-500">Project Manager</dt>
                <dd class="mt-1 truncate text-sm font-medium text-zinc-900">{{ $project->projectManager->name }}</dd>
            </div>
            <div class="bg-white px-4 py-3">
                <dt class="text-xs text-zinc-500">Deadline</dt>
                <dd class="mt-1"><x-ui.deadline :date="$project->current_end" :done="$project->completed_at !== null" /></dd>
            </div>
        </dl>
    </div>

    {{-- Tabs: four primary sections, the rest under More --}}
    <div class="mb-6 flex items-end gap-1 border-b border-zinc-200">
        <nav class="-mb-px flex min-w-0 flex-1 gap-1 overflow-x-auto" aria-label="Project sections">
            @foreach ($this::TABS as $key => $label)
                <button type="button" wire:key="tab-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                        'border-accent text-zinc-900' => $tab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800' => $tab !== $key,
                    ])
                    @if ($tab === $key) aria-current="page" @endif>
                    {{ $label }}
                    @if ($key === 'reports' && ($this->reportSummary['open'] ?? 0) > 0)
                        <span class="ms-1 rounded-full bg-zinc-100 px-1.5 text-xs tabular-nums text-zinc-600">{{ $this->reportSummary['open'] }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
        <flux:dropdown position="bottom" align="end">
            <button type="button" @class([
                '-mb-px flex shrink-0 items-center gap-1 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                'border-accent text-zinc-900' => array_key_exists($tab, $this::MORE_TABS),
                'border-transparent text-zinc-500 hover:text-zinc-800' => ! array_key_exists($tab, $this::MORE_TABS),
            ])>
                {{ $this::MORE_TABS[$tab] ?? 'More' }}
                <flux:icon.chevron-down variant="micro" />
            </button>
            <flux:menu>
                <flux:menu.item icon="flag" wire:click="$set('tab', 'milestones')">Milestones</flux:menu.item>
                <flux:menu.item icon="users" wire:click="$set('tab', 'team')">Team</flux:menu.item>
                @if ($can['update'])
                    <flux:menu.separator />
                    <flux:menu.item icon="cog-6-tooth" :href="route('projects.edit', $project)" wire:navigate>Project Settings</flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>
    </div>

    <livewire:approvals.progress subject-type="project" :subject-id="$project->id" spacing="mb-6" wire:key="approval-progress-{{ $project->id }}" />

    @include('partials.workflow.transition-modal')

    @if ($tab === 'overview')
        @php($summary = $this->taskSummary)
        @php($reports = $this->reportSummary)
        <div class="mb-6 grid gap-4 md:grid-cols-2">
            {{-- Tasks --}}
            <flux:card>
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Tasks</flux:heading>
                    <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'tasks')">View All Tasks</flux:button>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach ([
                        ['Total', $summary['total'], false, 'all'],
                        ['Done', $summary['done'], false, 'completed'],
                        ['In Progress', $summary['in_progress'], false, 'in_progress'],
                        ['Blocked', $summary['blocked'], true, 'blocked'],
                        ['Overdue', $summary['overdue'], true, 'overdue'],
                    ] as [$label, $value, $alert, $view])
                        <a href="{{ route('projects.show', ['project' => $project, 'tab' => 'tasks', 'view' => $view]) }}" wire:navigate wire:key="ts-{{ $view }}" class="rounded-lg bg-zinc-50 px-3 py-2 hover:bg-zinc-100">
                            <div @class(['text-lg font-semibold tabular-nums', 'text-red-600' => $alert && $value > 0, 'text-zinc-900' => ! ($alert && $value > 0)])>{{ $value }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $label }}</div>
                        </a>
                    @endforeach
                </div>
            </flux:card>

            {{-- Open reports --}}
            @if ($reports !== null)
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">Open Reports</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'reports')">View All Reports</flux:button>
                    </div>
                    @if ($reports['open'] > 0)
                        <div class="mt-3 flex items-baseline gap-2">
                            <span class="text-2xl font-semibold tabular-nums text-zinc-900">{{ $reports['open'] }}</span>
                            <span class="text-sm text-zinc-500">open</span>
                        </div>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach ($reports['types'] as $type)
                                <li wire:key="rt-{{ $loop->index }}" class="flex items-center gap-1.5 rounded-lg bg-zinc-50 px-2.5 py-1 text-sm">
                                    <flux:badge size="sm" :color="$type['color'] ?? 'zinc'" inset="top bottom">{{ $type['name'] }}</flux:badge>
                                    <span class="font-medium tabular-nums text-zinc-900">{{ $type['count'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <flux:text class="mt-3">Tidak ada report terbuka. Bug, maintenance, request, dan kendala Project akan muncul di sini.</flux:text>
                    @endif
                </flux:card>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                {{-- Current work --}}
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Current Work</flux:heading>
                        <flux:button size="sm" variant="ghost" wire:click="$set('tab', 'tasks')">View All Tasks</flux:button>
                    </div>
                    @forelse ($this->currentWork as $work)
                        <a href="{{ route('tasks.show', $work) }}" wire:navigate wire:key="cw-{{ $work->id }}" class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50 sm:grid-cols-[1fr_8rem_auto]">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $work->title }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $work->picAssignment?->user->name ?? 'Tanpa PIC' }}</div>
                            </div>
                            <x-ui.progress-bar :value="$work->progress_percentage" class="max-sm:order-last max-sm:col-span-2" />
                            <x-ui.master-badge :master="$work->status" class="justify-self-end" />
                        </a>
                    @empty
                        <x-ui.empty-state icon="check-circle" title="Belum ada Task." description="Tambahkan Task untuk mulai membagi pekerjaan Project." class="py-8">
                            @if ($can['createTask'])
                                <flux:button size="sm" variant="primary" icon="plus" :href="route('tasks.create', ['project' => $project->id])" wire:navigate>New Task</flux:button>
                            @endif
                        </x-ui.empty-state>
                    @endforelse
                </flux:card>

                {{-- Open issues --}}
                @if ($reports !== null)
                    <flux:card class="p-0!">
                        <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                            <flux:heading size="lg">Open Issues</flux:heading>
                            @if ($can['createReport'])
                                <flux:button size="sm" variant="ghost" icon="plus" :href="route('tickets.create', ['project' => $project->id])" wire:navigate>New Report</flux:button>
                            @endif
                        </div>
                        @forelse ($this->openIssues as $issue)
                            <a href="{{ route('tickets.show', $issue) }}" wire:navigate wire:key="oi-{{ $issue->id }}" class="flex items-start justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <flux:badge size="sm" :color="$issue->type->color ?? 'zinc'" inset="top bottom" class="uppercase">{{ $issue->type->name }}</flux:badge>
                                        <x-ui.master-badge :master="$issue->priority" />
                                    </div>
                                    <div class="mt-1 truncate text-sm font-medium text-zinc-900">{{ $issue->title }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $issue->currentDepartment->name }}{{ $issue->picAssignment ? ' · '.$issue->picAssignment->user->name : '' }}</div>
                                </div>
                                <x-ui.master-badge :master="$issue->status" class="shrink-0" />
                            </a>
                        @empty
                            <x-ui.empty-state icon="lifebuoy" title="Tidak ada kendala terbuka" description="Report bug, maintenance, dan request untuk Project ini akan muncul di sini." class="py-8" />
                        @endforelse
                    </flux:card>
                @endif
            </div>

            <div class="min-w-0 space-y-6">
                {{-- Department progress --}}
                @if ($summary['total'] > 0 && $this->departmentProgress->isNotEmpty())
                    <flux:card>
                        <flux:heading size="lg">Department Progress</flux:heading>
                        <div class="mt-4 space-y-3">
                            @foreach ($this->departmentProgress as $row)
                                <div wire:key="dp-{{ $row['department_id'] }}">
                                    <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                        <span class="truncate text-zinc-800">{{ $row['name'] }}</span>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ $row['tasks'] }} tasks</span>
                                    </div>
                                    <x-ui.progress-bar :value="$row['progress']" />
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                {{-- Project details (progressive disclosure) --}}
                <flux:card>
                    <flux:heading size="lg">About this Project</flux:heading>
                    <flux:text class="mt-2 line-clamp-4 whitespace-pre-line">{{ $project->description ?: 'Belum ada deskripsi.' }}</flux:text>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Owner</dt><dd class="truncate text-zinc-800">{{ $project->owner->name ?? 'Belum ditentukan' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Start date</dt><dd class="text-zinc-800">{{ $project->current_start->translatedFormat('d M Y') }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Health</dt><dd><x-ui.master-badge :master="$project->health" /></dd></div>
                    </dl>

                    <details class="group mt-4 border-t border-zinc-100 pt-3">
                        <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-medium text-zinc-700 hover:text-zinc-900">
                            More Details
                            <flux:icon.chevron-down variant="micro" class="transition group-open:rotate-180" />
                        </summary>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Category</dt><dd class="truncate text-zinc-800">{{ $project->category->name }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Priority</dt><dd><x-ui.master-badge :master="$project->priority" /></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Visibility</dt><dd class="text-zinc-800">{{ $project->visibility->label() }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Planned</dt><dd class="text-end text-zinc-800">{{ $project->planned_start->translatedFormat('d M Y') }} – {{ $project->planned_end->translatedFormat('d M Y') }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Current</dt><dd class="text-end text-zinc-800">{{ $project->current_start->translatedFormat('d M Y') }} – {{ $project->current_end->translatedFormat('d M Y') }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">Actual</dt><dd class="text-end text-zinc-800">{{ $project->actual_start?->translatedFormat('d M Y') ?? 'Belum dimulai' }}{{ $project->actual_end ? ' – '.$project->actual_end->translatedFormat('d M Y') : '' }}</dd></div>
                        </dl>
                        @if ($this->lastScheduleChange)
                            <flux:text size="sm" class="mt-3">
                                Perubahan jadwal terakhir oleh {{ $this->lastScheduleChange->changedBy->name ?? 'System' }}, {{ $this->lastScheduleChange->created_at?->diffForHumans() }}: “{{ $this->lastScheduleChange->reason }}”
                            </flux:text>
                        @endif
                        @if ($this->upcomingMilestones->isNotEmpty())
                            <div class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-400">Upcoming milestones</div>
                            <ul class="mt-1 space-y-1">
                                @foreach ($this->upcomingMilestones->take(3) as $milestone)
                                    <li wire:key="um-{{ $milestone->id }}" class="flex items-center justify-between gap-3 text-sm">
                                        <span class="truncate text-zinc-800">{{ $milestone->name }}</span>
                                        <span @class(['shrink-0 text-xs', 'text-red-600' => $milestone->isOverdue(), 'text-zinc-500' => ! $milestone->isOverdue()])>{{ $milestone->current_due_date->translatedFormat('d M Y') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-400">Departments</div>
                        <ul class="mt-1 space-y-1">
                            @foreach ($project->departments as $projectDepartment)
                                <li wire:key="dept-{{ $projectDepartment->id }}" class="flex items-center justify-between gap-2 text-sm">
                                    <span class="truncate text-zinc-800">{{ $projectDepartment->department->name }}{{ $projectDepartment->leadUser ? ' · '.$projectDepartment->leadUser->name : '' }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ $projectDepartment->is_lead ? 'Lead' : 'Supporting' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </flux:card>
            </div>
        </div>
    @elseif ($tab === 'tasks')
        <livewire:tasks.table mode="project" :project-id="$project->id" wire:key="tasks-{{ $project->id }}" />
    @elseif ($tab === 'reports')
        <livewire:tickets.table mode="project" :project-id="$project->id" wire:key="tickets-{{ $project->id }}" />
    @elseif ($tab === 'milestones')
        <livewire:projects.milestones :project-id="$project->id" wire:key="milestones-{{ $project->id }}" />
    @elseif ($tab === 'team')
        <livewire:projects.team :project-id="$project->id" wire:key="team-{{ $project->id }}" />
    @else
        <livewire:projects.activity :project-id="$project->id" wire:key="activity-{{ $project->id }}" />
    @endif

    {{-- Modals --}}
    @if ($can['progress'])
        <flux:modal name="project-progress-mode" class="w-full max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Progress Calculation</flux:heading>
                    <flux:text class="mt-1">Pilih cara progress project dihitung.</flux:text>
                </div>
                <flux:radio.group wire:model="progressMode" label="Mode">
                    <flux:radio value="manual" label="Manual" description="Project Manager mengisi progress secara manual." />
                    <flux:radio value="task_weighted" label="Based on Tasks" description="Menghitung progress Project secara otomatis dari bobot dan progress Task aktif. Task yang dibatalkan tidak dihitung." />
                </flux:radio.group>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button variant="primary"
                        x-on:click="$wire.progressMode === 'task_weighted' && '{{ $project->progress_mode->value }}' !== 'task_weighted'
                            ? hwConfirm({ title: 'Gunakan progress otomatis?', text: 'Progress Project akan dihitung dari bobot dan progress Task. Progress manual tidak dapat diedit selama mode ini aktif.', confirmText: 'Gunakan otomatis' }).then(ok => ok && $wire.saveProgressMode())
                            : $wire.saveProgressMode()">Save</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    @if ($can['visibility'])
        <flux:modal name="project-visibility" class="w-full max-w-md">
            <form wire:submit="saveVisibility" class="space-y-6">
                <div>
                    <flux:heading size="lg">Project visibility</flux:heading>
                    <flux:text class="mt-1">Visibility hanya mengatur siapa yang dapat melihat project, bukan siapa yang dapat mengubahnya.</flux:text>
                </div>
                <flux:radio.group wire:model="visibility" label="Visibility">
                    @foreach (\App\Enums\ProjectVisibility::cases() as $option)
                        <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description().($this->canSetVisibility($option) ? '' : ' (butuh izin administrator)')" :disabled="! $this->canSetVisibility($option)" wire:key="vis-{{ $option->value }}" />
                    @endforeach
                </flux:radio.group>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save visibility</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['progress'])
        <flux:modal name="project-progress" class="w-full max-w-md">
            <form wire:submit="saveProgress" class="space-y-6">
                <div>
                    <flux:heading size="lg">Update progress</flux:heading>
                    <flux:text class="mt-1">Progress Project diisi manual oleh Project Manager.</flux:text>
                </div>
                <div x-data="{ value: $wire.entangle('progress') }" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:label>Progress</flux:label>
                        <span class="text-2xl font-semibold tabular-nums" x-text="value + '%'"></span>
                    </div>
                    <input type="range" min="0" max="100" step="5" x-model.number="value" class="w-full accent-[var(--color-accent)]" aria-label="Progress" />
                    <flux:input wire:model="progress" type="number" min="0" max="100" class="max-w-32" aria-label="Progress percentage" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save progress</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="project-health" class="w-full max-w-md">
            <form wire:submit="saveHealth" class="space-y-6">
                <div>
                    <flux:heading size="lg">Update project health</flux:heading>
                    <flux:text class="mt-1">Penilaian kondisi project oleh Project Manager.</flux:text>
                </div>
                <flux:radio.group wire:model="healthId" label="Health">
                    @foreach ($this->healthOptions as $option)
                        <flux:radio :value="(string) $option->id" :label="$option->name" :description="$option->description" wire:key="h-{{ $option->id }}" />
                    @endforeach
                </flux:radio.group>
                <flux:textarea wire:model="healthNote" label="Note" rows="2" placeholder="Opsional: alasan singkat." />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save health</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['schedule'])
        <flux:modal name="project-schedule" class="w-full max-w-md">
            <form wire:submit="saveSchedule" class="space-y-6">
                <div>
                    <flux:heading size="lg">Change Project Schedule</flux:heading>
                    <flux:text class="mt-1">Rencana awal (baseline) tidak berubah. Perubahan tercatat di riwayat.</flux:text>
                </div>
                <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm">
                    <div class="text-zinc-500">Current deadline</div>
                    <div class="font-medium text-zinc-900">{{ $project->current_end->translatedFormat('d M Y') }}</div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="scheduleStart" type="date" label="Start date" />
                    <flux:input wire:model="scheduleEnd" type="date" label="New deadline" badge="Required" />
                </div>
                <flux:textarea wire:model="scheduleReason" label="Reason" badge="Required" rows="3" placeholder="Contoh: menunggu persetujuan desain dari client." />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Update schedule</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($can['complete'])
        <flux:modal name="project-complete" class="w-full max-w-md">
            <form wire:submit="complete" class="space-y-6">
                <div>
                    <flux:heading size="lg">Complete project?</flux:heading>
                    <flux:text class="mt-1">Status menjadi selesai, tanggal selesai aktual dicatat, dan progress manual menjadi 100%.</flux:text>
                </div>
                <flux:input wire:model="completedOn" type="date" label="Completion date" badge="Required" :max="today()->toDateString()" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="check-badge">Complete project</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
