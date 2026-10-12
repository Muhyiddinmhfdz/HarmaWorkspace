<?php

use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Ticket\Support\TicketQueue;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\TicketType;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Shared report (ticket) list: Reports (all), My Reports (mine), the project
 * Reports tab (project) and the Department Inbox work queue (inbox). Every
 * query starts from Ticket::visibleTo() via TicketQueue, which also produces
 * all quick-view counters in one aggregate query.
 *
 * Phase 6: "Report" in the UI (backend stays Ticket), a few primary views
 * per mode with the rest under "More", and compact columns per mode.
 */
new class extends Component {
    use WithFeedback, WithPagination;

    #[Locked]
    public string $mode = 'all';

    #[Locked]
    public ?int $projectId = null;

    #[Url(as: 'view', except: '')]
    public string $quick = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Inbox: '' = all my departments, otherwise a department id. */
    #[Url(as: 'department', except: '')]
    public string $inboxDepartment = '';

    #[Url(except: '')]
    public string $departmentId = '';

    #[Url(except: '')]
    public string $statusId = '';

    #[Url(except: '')]
    public string $priorityId = '';

    #[Url(except: '')]
    public string $typeId = '';

    #[Url(except: '')]
    public string $clientId = '';

    #[Url(except: '')]
    public string $filterProjectId = '';

    #[Url(except: '')]
    public string $picId = '';

    #[Url(except: '')]
    public string $requesterId = '';

    #[Url(except: '')]
    public string $deadline = '';

    public bool $showFilters = false;

    #[Locked]
    public ?int $assignTicketId = null;

    public ?int $assignUserId = null;

    public function mount(string $mode = 'all', ?int $projectId = null): void
    {
        $this->mode = in_array($mode, ['all', 'mine', 'project', 'inbox'], true) ? $mode : 'all';
        $this->projectId = $this->mode === 'project' ? $projectId : null;

        if ($this->projectId !== null) {
            $this->authorize('view', Project::query()->findOrFail($this->projectId));
        }

        $this->authorize('viewAny', Ticket::class);

        if ($this->mode === 'inbox' && $this->inboxDepartment !== '') {
            $department = Department::query()->findOrFail((int) $this->inboxDepartment);
            $this->authorize('viewInbox', [Ticket::class, $department]);
        }

        if ($this->quick === '' || ! array_key_exists($this->quick, $this->definitions())) {
            $this->quick = $this->defaultQuick();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['quick', 'search', 'inboxDepartment', 'departmentId', 'statusId', 'priorityId', 'typeId', 'clientId', 'filterProjectId', 'picId', 'requesterId', 'deadline'], true)) {
            $this->resetPage();
        }

        if ($property === 'inboxDepartment' && $this->inboxDepartment !== '') {
            $this->authorize('viewInbox', [Ticket::class, Department::query()->findOrFail((int) $this->inboxDepartment)]);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'departmentId', 'statusId', 'priorityId', 'typeId', 'clientId', 'filterProjectId', 'picId', 'requesterId', 'deadline');
        $this->resetPage();
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([$this->departmentId, $this->statusId, $this->priorityId, $this->typeId, $this->clientId, $this->filterProjectId, $this->picId, $this->requesterId, $this->deadline]));
    }

    /**
     * Quick views per mode (key => label): primary views first.
     *
     * @return array<string, string>
     */
    private function definitions(): array
    {
        return $this->primaryDefinitions() + $this->moreDefinitions();
    }

    /**
     * @return array<string, string>
     */
    private function primaryDefinitions(): array
    {
        return match ($this->mode) {
            'mine' => ['waiting_for_me' => 'Waiting for Me', 'assigned_to_me' => 'Assigned to Me', 'requested' => 'Reported by Me', 'my_resolved' => 'Recently Resolved'],
            'project' => ['open' => 'Open', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'resolved' => 'Resolved'],
            'inbox' => ['new' => 'New', 'unassigned' => 'Unassigned', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'review' => 'Review'],
            default => ['open' => 'Open', 'mine' => 'My Reports', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'review' => 'Review'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function moreDefinitions(): array
    {
        return match ($this->mode) {
            'mine' => ['collaborating' => 'Collaborating', 'my_closed' => 'Closed'],
            'project' => ['all' => 'All'],
            'inbox' => ['overdue' => 'Overdue', 'assigned' => 'Assigned', 'all' => 'All'],
            default => ['unassigned' => 'Unassigned', 'overdue' => 'Overdue', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'],
        };
    }

    /**
     * Actionable default: My Tickets opens on what waits for the user.
     */
    private function defaultQuick(): string
    {
        return match ($this->mode) {
            'mine' => collect(['waiting_for_me', 'assigned_to_me', 'requested'])
                ->first(fn (string $key): bool => ($this->counts[$key] ?? 0) > 0) ?? 'waiting_for_me',
            'inbox' => 'new',
            default => 'open',
        };
    }

    /**
     * Departments whose inbox the user may open: active memberships, or every
     * active department for ticket.view-all / ticket.manage-inbox holders.
     *
     * @return Collection<int, Department>
     */
    #[Computed]
    public function inboxDepartments(): Collection
    {
        $user = auth()->user();

        if ($user->can('ticket.view-all') || $user->can('ticket.manage-inbox')) {
            return Department::query()->active()->orderBy('name')->get(['id', 'name']);
        }

        return Department::query()
            ->whereIn('id', $user->activeMemberships()->select('department_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Department ids shown in the inbox ("all my departments" = memberships).
     *
     * @return list<int>
     */
    #[Computed]
    public function inboxDepartmentIds(): array
    {
        if ($this->inboxDepartment !== '') {
            return [(int) $this->inboxDepartment];
        }

        $mine = array_map(intval(...), auth()->user()->activeMemberships()->pluck('department_id')->all());

        return $mine !== [] ? $mine : $this->inboxDepartments->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->projectId ? Project::query()->with('status')->find($this->projectId) : null;
    }

    /**
     * @return Builder<Ticket>
     */
    private function base(): Builder
    {
        $user = auth()->user();

        return app(TicketQueue::class)->base($user)
            ->when($this->mode === 'project', fn (Builder $query) => $query->where('tickets.project_id', $this->projectId))
            ->when($this->mode === 'inbox', fn (Builder $query) => $query->whereIn('tickets.current_department_id', $this->inboxDepartmentIds))
            ->when($this->mode === 'mine', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('tickets.requester_user_id', $user->id)
                ->orWhere('tickets.created_by', $user->id)
                ->orWhereHas('activeAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id))));
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(TicketQueue::class)->counts($this->base(), array_keys($this->definitions()), auth()->user());
    }

    /**
     * Primary quick views with real counts. Overdue joins the primary views
     * of the inbox only when something is overdue.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function quickViews(): array
    {
        $views = [];

        foreach ($this->primaryDefinitions() as $key => $label) {
            $views[] = ['key' => $key, 'label' => $label, 'count' => $this->counts[$key] ?? 0];
        }

        if ($this->mode === 'inbox' && ($this->counts['overdue'] ?? 0) > 0) {
            $views[] = ['key' => 'overdue', 'label' => 'Overdue', 'count' => $this->counts['overdue']];
        }

        return $views;
    }

    /**
     * Secondary views under "More" (with counts, same aggregate query).
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function moreViews(): array
    {
        $primary = array_column($this->quickViews, 'key');
        $views = [];

        foreach ($this->moreDefinitions() as $key => $label) {
            if (! in_array($key, $primary, true)) {
                $views[] = ['key' => $key, 'label' => $label, 'count' => $this->counts[$key] ?? 0];
            }
        }

        return $views;
    }

    #[Computed]
    public function tickets(): LengthAwarePaginator
    {
        $user = auth()->user();
        $queue = app(TicketQueue::class);

        $query = $this->base()
            ->join('priorities as qp', 'qp.id', '=', 'tickets.priority_id')
            ->select('tickets.*')
            ->addSelect(['received_at' => TicketTransfer::query()->select('transferred_at')->whereColumn('ticket_id', 'tickets.id')->latest('transferred_at')->limit(1)])
            ->with([
                'status',
                'priority',
                'type:id,name,color',
                'currentDepartment:id,name',
                'requester:id,name',
                'picAssignment.user:id,name',
                'project:id,name',
            ]);

        $queue->apply($query, $this->quick, $user);

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';

        $query
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('tickets.number', 'like', $pattern)
                ->orWhere('tickets.title', 'like', $pattern)
                ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $pattern))
                ->orWhereHas('requester', fn (Builder $requester) => $requester->where('name', 'like', $pattern))))
            ->when($this->departmentId !== '' && $this->mode !== 'inbox', fn (Builder $query) => $query->where('tickets.current_department_id', (int) $this->departmentId))
            ->when($this->statusId !== '', fn (Builder $query) => $query->where('tickets.status_id', (int) $this->statusId))
            ->when($this->priorityId !== '', fn (Builder $query) => $query->where('tickets.priority_id', (int) $this->priorityId))
            ->when($this->typeId !== '', fn (Builder $query) => $query->where('tickets.ticket_type_id', (int) $this->typeId))
            ->when($this->clientId !== '', fn (Builder $query) => $query->where('tickets.client_id', (int) $this->clientId))
            ->when($this->filterProjectId === 'none', fn (Builder $query) => $query->whereNull('tickets.project_id'))
            ->when(ctype_digit($this->filterProjectId), fn (Builder $query) => $query->where('tickets.project_id', (int) $this->filterProjectId))
            ->when($this->picId !== '', fn (Builder $query) => $query->whereHas('picAssignment', fn (Builder $pic) => $pic->where('user_id', (int) $this->picId)))
            ->when($this->requesterId !== '', fn (Builder $query) => $query->where('tickets.requester_user_id', (int) $this->requesterId))
            ->when($this->deadline === 'overdue', fn (Builder $query) => $query->overdue())
            ->when($this->deadline === '7', fn (Builder $query) => $query->whereBetween('tickets.current_due_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()]))
            ->when($this->deadline === 'none', fn (Builder $query) => $query->whereNull('tickets.current_due_at'));

        if ($this->mode === 'inbox') {
            // Work queue: active first, most urgent priority, oldest request first.
            $query->orderByRaw('(qs.is_terminal = 1 OR qs.counts_as_completed = 1)')
                ->orderByDesc('qp.level')
                ->orderBy('tickets.created_at');
        } else {
            $queue->orderActionable($query, $user);
        }

        return $query->paginate(15);
    }

    /**
     * Names of the related projects on the current page the user may see
     * (ticket visibility is independent from project visibility; hidden
     * projects are not named). One query per page.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function visibleProjectNames(): array
    {
        $ids = $this->tickets->getCollection()->pluck('project_id')->filter()->unique()->values()->all();

        if ($ids === [] || $this->mode === 'project') {
            return [];
        }

        return Project::query()->visibleTo(auth()->user())->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @return array{statuses: Collection<int, Status>, priorities: Collection<int, Priority>, types: Collection<int, TicketType>, departments: Collection<int, Department>, clients: Collection<int, Client>, projects: Collection<int, Project>, people: Collection<int, User>}
     */
    #[Computed]
    public function filterOptions(): array
    {
        $base = $this->base();

        return [
            'statuses' => Status::query()->forScope(MasterScope::Ticket)->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Ticket)->orderByDesc('level')->get(['id', 'name']),
            'types' => TicketType::query()->orderBy('sort_order')->get(['id', 'name']),
            'departments' => Department::query()->whereIn('id', (clone $base)->select('tickets.current_department_id'))->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->whereIn('id', (clone $base)->whereNotNull('tickets.client_id')->select('tickets.client_id'))->orderBy('name')->get(['id', 'name']),
            'projects' => $this->projectId ? collect() : Project::query()->visibleTo(auth()->user())->whereIn('id', (clone $base)->whereNotNull('tickets.project_id')->select('tickets.project_id'))->orderBy('name')->get(['id', 'name']),
            'people' => User::query()->whereIn('id', (clone $base)->select('tickets.requester_user_id'))
                ->orWhereIn('id', \App\Models\TicketAssignment::query()->whereNull('ended_at')->whereIn('ticket_id', (clone $base)->select('tickets.id'))->select('user_id'))
                ->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Departments the user leads, loaded once to show inbox row actions
     * without per-row queries. Every action re-authorizes in the backend.
     *
     * @return list<int>
     */
    #[Computed]
    public function ledDepartmentIds(): array
    {
        return array_map(intval(...), auth()->user()->activeMemberships()->where('is_leader', true)->pluck('department_id')->all());
    }

    public function canManage(Ticket $ticket): bool
    {
        $user = auth()->user();

        return $ticket->isActive()
            && (in_array($ticket->current_department_id, $this->ledDepartmentIds, true) || $user->can('ticket.manage-inbox') || $user->can('ticket.assign'));
    }

    public function accept(int $ticketId, AcceptTicket $acceptTicket): void
    {
        $ticket = $this->base()->select('tickets.*')->findOrFail($ticketId);
        $this->authorize('accept', $ticket);

        $acceptTicket->handle($ticket, auth()->user());

        $this->notify('success', 'Laporan '.$ticket->number.' berhasil diterima.');
        $this->refreshList();
    }

    public function openAssign(int $ticketId): void
    {
        $ticket = $this->base()->select('tickets.*')->findOrFail($ticketId);
        $this->authorize('assign', $ticket);

        $this->assignTicketId = $ticket->id;
        $this->assignUserId = null;
        $this->resetValidation();
        Flux::modal('ticket-quick-assign-'.$this->getId())->show();
    }

    public function saveAssign(ManageTicketAssignments $assignments): void
    {
        $ticket = $this->base()->select('tickets.*')->findOrFail((int) $this->assignTicketId);
        $this->authorize('assign', $ticket);
        $this->validate(['assignUserId' => ['required', 'integer']], ['assignUserId.required' => 'PIC wajib dipilih.']);

        $ticket = $assignments->assign($ticket, (int) $this->assignUserId, auth()->user());
        $name = User::query()->whereKey($this->assignUserId)->value('name');

        Flux::modal('ticket-quick-assign-'.$this->getId())->close();
        $this->notify('success', 'Laporan berhasil ditugaskan ke '.$name.'.');
        $this->refreshList();
    }

    /**
     * Members of the department of the ticket being assigned.
     *
     * @return list<int>
     */
    #[Computed]
    public function assignableIds(): array
    {
        $departmentId = $this->assignTicketId ? Ticket::query()->whereKey($this->assignTicketId)->value('current_department_id') : null;

        return $departmentId === null ? [] : array_map(intval(...), \App\Models\DepartmentMembership::query()->where('department_id', $departmentId)->whereNull('ended_at')->pluck('user_id')->all());
    }

    private function refreshList(): void
    {
        unset($this->tickets, $this->counts, $this->quickViews, $this->moreViews, $this->visibleProjectNames);
    }
}; ?>

<div>
    @if ($mode === 'inbox')
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="w-full sm:w-72">
                <flux:select wire:model.live="inboxDepartment" aria-label="Department">
                    <flux:select.option value="">All my departments</flux:select.option>
                    @foreach ($this->inboxDepartments as $option)
                        <flux:select.option :value="$option->id" wire:key="ib-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    @endif

    {{-- Quick views: primary with counts, the rest under More --}}
    <div class="mb-4 flex items-end gap-1 border-b border-zinc-200">
        <nav class="-mb-px flex min-w-0 flex-1 gap-1 overflow-x-auto" aria-label="Report views">
            @foreach ($this->quickViews as $view)
                <button type="button" wire:key="tqv-{{ $view['key'] }}" wire:click="$set('quick', '{{ $view['key'] }}')"
                    @class([
                        'flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                        'border-accent text-zinc-900' => $quick === $view['key'],
                        'border-transparent text-zinc-500 hover:text-zinc-800' => $quick !== $view['key'],
                    ])
                    @if ($quick === $view['key']) aria-current="true" @endif>
                    {{ $view['label'] }}
                    <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-zinc-900 text-white' => $quick === $view['key'], 'bg-zinc-100 text-zinc-600' => $quick !== $view['key']])>{{ $view['count'] }}</span>
                </button>
            @endforeach
        </nav>
        @if ($this->moreViews !== [])
            @php($moreSelected = collect($this->moreViews)->firstWhere('key', $quick))
            <flux:dropdown position="bottom" align="end">
                <button type="button" @class([
                    '-mb-px flex shrink-0 items-center gap-1 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                    'border-accent text-zinc-900' => $moreSelected !== null,
                    'border-transparent text-zinc-500 hover:text-zinc-800' => $moreSelected === null,
                ])>
                    {{ $moreSelected['label'] ?? 'More' }}
                    <flux:icon.chevron-down variant="micro" />
                </button>
                <flux:menu>
                    @foreach ($this->moreViews as $view)
                        <flux:menu.item wire:key="tmv-{{ $view['key'] }}" wire:click="$set('quick', '{{ $view['key'] }}')">
                            <span class="flex w-full items-center justify-between gap-6">{{ $view['label'] }} <span class="text-xs tabular-nums text-zinc-500">{{ $view['count'] }}</span></span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>

    {{-- Search & filters --}}
    <div class="mb-4 space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search number, title, client, requester..." clearable aria-label="Search reports" />
            </div>
            <flux:button icon="adjustments-horizontal" wire:click="$toggle('showFilters')" :variant="$showFilters ? 'filled' : 'outline'">
                More Filters
                @if ($this->activeFilterCount() > 0)
                    <flux:badge size="sm" color="indigo" class="ms-1">{{ $this->activeFilterCount() }}</flux:badge>
                @endif
            </flux:button>
            @if ($this->activeFilterCount() > 0 || $search !== '')
                <flux:button variant="ghost" wire:click="resetFilters">Reset</flux:button>
            @endif
            <flux:spacer />
            @can('create', App\Models\Ticket::class)
                @if (! $this->project || (! $this->project->isArchived() && ! $this->project->status->is_terminal))
                    <flux:button variant="primary" icon="plus" :href="route('tickets.create', $projectId ? ['project' => $projectId] : [])" wire:navigate>New Report</flux:button>
                @endif
            @endcan
        </div>

        @if ($showFilters)
            <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @if ($mode !== 'inbox')
                    <flux:select wire:model.live="departmentId" label="Department">
                        <flux:select.option value="">All departments</flux:select.option>
                        @foreach ($this->filterOptions['departments'] as $option)
                            <flux:select.option :value="$option->id" wire:key="fd-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:select wire:model.live="statusId" label="Status">
                    <flux:select.option value="">All status</flux:select.option>
                    @foreach ($this->filterOptions['statuses'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fs-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="priorityId" label="Priority">
                    <flux:select.option value="">All priority</flux:select.option>
                    @foreach ($this->filterOptions['priorities'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fp-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="typeId" label="Type">
                    <flux:select.option value="">All types</flux:select.option>
                    @foreach ($this->filterOptions['types'] as $option)
                        <flux:select.option :value="$option->id" wire:key="ft-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="clientId" label="Client">
                    <flux:select.option value="">All clients</flux:select.option>
                    @foreach ($this->filterOptions['clients'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fc-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @unless ($projectId)
                    <flux:select wire:model.live="filterProjectId" label="Project">
                        <flux:select.option value="">All projects</flux:select.option>
                        <flux:select.option value="none">Without project</flux:select.option>
                        @foreach ($this->filterOptions['projects'] as $option)
                            <flux:select.option :value="$option->id" wire:key="fpr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endunless
                <flux:select wire:model.live="picId" label="PIC">
                    <flux:select.option value="">Anyone</flux:select.option>
                    @foreach ($this->filterOptions['people'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fpic-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="requesterId" label="Requester">
                    <flux:select.option value="">Anyone</flux:select.option>
                    @foreach ($this->filterOptions['people'] as $option)
                        <flux:select.option :value="$option->id" wire:key="freq-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="deadline" label="Deadline">
                    <flux:select.option value="">Any time</flux:select.option>
                    <flux:select.option value="overdue">Overdue</flux:select.option>
                    <flux:select.option value="7">Next 7 days</flux:select.option>
                    <flux:select.option value="none">No deadline</flux:select.option>
                </flux:select>
            </div>
        @endif
    </div>

    @if ($this->tickets->isEmpty())
        <x-ui.table-card>
            @if ($search !== '' || $this->activeFilterCount() > 0)
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada report yang cocok" description="Coba ubah kata kunci atau filter.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @elseif ($mode === 'inbox' && $quick === 'new')
                <x-ui.empty-state icon="inbox" title="Tidak ada Report baru." description="Semua report untuk Department ini sudah diterima." />
            @elseif ($mode === 'inbox')
                <x-ui.empty-state icon="inbox-stack" title="Belum ada Report yang perlu ditangani." description="Report baru untuk Department ini akan muncul di sini." />
            @elseif ($mode === 'project')
                <x-ui.empty-state icon="lifebuoy" title="Belum ada Report." description="Bug, maintenance, request, dan kendala Project akan muncul di sini.">
                    @can('create', App\Models\Ticket::class)
                        @if ($this->project && ! $this->project->isArchived() && ! $this->project->status->is_terminal)
                            <flux:button size="sm" variant="primary" icon="plus" :href="route('tickets.create', ['project' => $projectId])" wire:navigate>New Report</flux:button>
                        @endif
                    @endcan
                </x-ui.empty-state>
            @elseif ($mode === 'mine')
                <x-ui.empty-state icon="chat-bubble-left-right" title="Tidak ada report di tampilan ini." description="Report yang Anda buat, pegang sebagai PIC, atau bantu sebagai kolaborator akan muncul di sini." />
            @else
                <x-ui.empty-state icon="lifebuoy" title="Tidak ada report di tampilan ini." description="Bug, maintenance, request, issue, dan catatan pekerjaan yang dapat Anda lihat akan muncul di sini." />
            @endif
        </x-ui.table-card>
    @else
        <x-ui.table-card class="hidden md:block">
            <flux:table :paginate="$this->tickets" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column>Report</flux:table.column>
                    @if ($mode === 'inbox')
                        <flux:table.column class="max-xl:hidden">Type</flux:table.column>
                        <flux:table.column>Priority</flux:table.column>
                        <flux:table.column class="max-lg:hidden">Requester</flux:table.column>
                        <flux:table.column>PIC</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column class="max-lg:hidden">Age</flux:table.column>
                    @elseif ($mode === 'mine')
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Who Is Handling</flux:table.column>
                        <flux:table.column class="max-lg:hidden">Last Update</flux:table.column>
                    @else
                        <flux:table.column class="max-xl:hidden">Type</flux:table.column>
                        <flux:table.column>PIC / Department</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column class="max-lg:hidden">Priority</flux:table.column>
                        <flux:table.column class="max-xl:hidden">Updated</flux:table.column>
                    @endif
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tickets as $ticket)
                        @php($receivedAt = $ticket->received_at ? \Illuminate\Support\Carbon::parse($ticket->received_at) : $ticket->created_at)
                        @php($staleUnassigned = $ticket->accepted_at && ! $ticket->picAssignment && $ticket->accepted_at->lt(now()->subDay()) && $ticket->isActive())
                        <flux:table.row :key="$ticket->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('tickets.show', $ticket) }}')">
                            <flux:table.cell class="max-w-96">
                                <a href="{{ route('tickets.show', $ticket) }}" wire:navigate x-on:click.stop class="block truncate font-medium text-zinc-900 hover:underline">{{ $ticket->title }}</a>
                                <div class="mt-0.5 flex min-w-0 items-center gap-2 text-xs text-zinc-500">
                                    <span class="shrink-0 font-mono">{{ $ticket->number }}</span>
                                    @if ($mode === 'mine')
                                        <flux:badge size="sm" :color="$ticket->type->color ?? 'zinc'" inset="top bottom" class="shrink-0 uppercase">{{ $ticket->type->name }}</flux:badge>
                                    @endif
                                    @if ($ticket->project_id && isset($this->visibleProjectNames[$ticket->project_id]))
                                        <span class="truncate">· {{ $this->visibleProjectNames[$ticket->project_id] }}</span>
                                    @endif
                                    @if ($ticket->isOverdue())
                                        <span class="shrink-0 text-red-600">· Overdue</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            @if ($mode === 'inbox')
                                <flux:table.cell class="max-xl:hidden"><flux:badge size="sm" :color="$ticket->type->color ?? 'zinc'" inset="top bottom" class="uppercase">{{ $ticket->type->name }}</flux:badge></flux:table.cell>
                                <flux:table.cell><x-ui.master-badge :master="$ticket->priority" /></flux:table.cell>
                                <flux:table.cell class="max-lg:hidden">{{ $ticket->requester->name }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($ticket->picAssignment)
                                        {{ $ticket->picAssignment->user->name }}
                                    @elseif ($staleUnassigned)
                                        <span class="inline-flex items-center gap-1 text-sm text-amber-600" title="Diterima {{ $ticket->accepted_at->diffForHumans() }} tanpa PIC">
                                            <flux:icon.exclamation-circle variant="micro" /> Belum ada PIC
                                        </span>
                                    @else
                                        <span class="text-sm text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><x-ui.master-badge :master="$ticket->status" /></flux:table.cell>
                                <flux:table.cell class="max-lg:hidden">
                                    <span class="text-sm text-zinc-600" title="{{ $receivedAt->translatedFormat('d M Y H:i') }}">{{ $receivedAt->diffForHumans(short: true) }}</span>
                                </flux:table.cell>
                            @elseif ($mode === 'mine')
                                <flux:table.cell><x-ui.master-badge :master="$ticket->status" /></flux:table.cell>
                                <flux:table.cell>
                                    <span class="block text-sm text-zinc-800">{{ $ticket->picAssignment?->user->name ?? 'Belum ada PIC' }}</span>
                                    <span class="block text-xs text-zinc-500">{{ $ticket->currentDepartment->name }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="max-lg:hidden"><span class="text-sm text-zinc-500" title="{{ $ticket->updated_at?->translatedFormat('d M Y H:i') }}">{{ $ticket->updated_at?->diffForHumans() }}</span></flux:table.cell>
                            @else
                                <flux:table.cell class="max-xl:hidden"><flux:badge size="sm" :color="$ticket->type->color ?? 'zinc'" inset="top bottom" class="uppercase">{{ $ticket->type->name }}</flux:badge></flux:table.cell>
                                <flux:table.cell>
                                    <span class="block text-sm text-zinc-800">{{ $ticket->picAssignment?->user->name ?? '—' }}</span>
                                    <span class="block text-xs text-zinc-500">{{ $ticket->currentDepartment->name }}</span>
                                </flux:table.cell>
                                <flux:table.cell><x-ui.master-badge :master="$ticket->status" /></flux:table.cell>
                                <flux:table.cell class="max-lg:hidden"><x-ui.master-badge :master="$ticket->priority" /></flux:table.cell>
                                <flux:table.cell class="max-xl:hidden"><span class="text-sm text-zinc-500" title="{{ $ticket->updated_at?->translatedFormat('d M Y H:i') }}">{{ $ticket->updated_at?->diffForHumans() }}</span></flux:table.cell>
                            @endif
                            <flux:table.cell align="end">
                                <div x-on:click.stop class="flex items-center justify-end gap-1">
                                    @if ($mode === 'inbox' && $this->canManage($ticket))
                                        @if (! $ticket->accepted_at)
                                            <flux:button size="sm" variant="primary" wire:click="accept({{ $ticket->id }})" wire:loading.attr="disabled" wire:target="accept({{ $ticket->id }})">Accept</flux:button>
                                        @elseif (! $ticket->picAssignment)
                                            <flux:button size="sm" wire:click="openAssign({{ $ticket->id }})">Assign</flux:button>
                                        @endif
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="chevron-right" :href="route('tickets.show', $ticket)" wire:navigate aria-label="Open {{ $ticket->number }}" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table-card>

        {{-- Mobile cards --}}
        <div class="space-y-3 md:hidden">
            @foreach ($this->tickets as $ticket)
                @php($receivedAt = $ticket->received_at ? \Illuminate\Support\Carbon::parse($ticket->received_at) : $ticket->created_at)
                <div wire:key="tcard-{{ $ticket->id }}" class="rounded-xl border border-zinc-200 bg-white p-4">
                    <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="block">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-zinc-900">{{ $ticket->title }}</div>
                                <div class="font-mono text-xs text-zinc-500">{{ $ticket->number }}</div>
                            </div>
                            <x-ui.master-badge :master="$ticket->status" />
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$ticket->type->color ?? 'zinc'" inset="top bottom" class="uppercase">{{ $ticket->type->name }}</flux:badge>
                            <x-ui.master-badge :master="$ticket->priority" />
                            <span class="truncate text-xs text-zinc-500">{{ $mode === 'inbox' ? 'Requester '.$ticket->requester->name : $ticket->currentDepartment->name }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-3 text-xs text-zinc-500">
                            <span class="truncate">{{ $mode === 'inbox' ? 'Received '.$receivedAt->diffForHumans() : ($ticket->picAssignment?->user->name ?? 'Belum ada PIC') }}</span>
                            @if ($ticket->current_due_at)
                                @php($due = \App\Domain\Project\Support\Deadline::for($ticket->current_due_at, ! $ticket->isActive()))
                                <span @class(['shrink-0', 'text-red-600' => $due->tone === 'red', 'text-amber-600' => $due->tone === 'amber'])>{{ $due->label }}</span>
                            @endif
                        </div>
                    </a>
                    @if ($mode === 'inbox' && $this->canManage($ticket) && (! $ticket->accepted_at || ! $ticket->picAssignment))
                        <div class="mt-3 flex gap-2 border-t border-zinc-100 pt-3">
                            @if (! $ticket->accepted_at)
                                <flux:button size="sm" variant="primary" class="flex-1" wire:click="accept({{ $ticket->id }})" wire:loading.attr="disabled" wire:target="accept({{ $ticket->id }})">Accept</flux:button>
                            @else
                                <flux:button size="sm" class="flex-1" wire:click="openAssign({{ $ticket->id }})">Assign PIC</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" :href="route('tickets.show', $ticket)" wire:navigate>Open</flux:button>
                        </div>
                    @endif
                </div>
            @endforeach
            <flux:pagination :paginator="$this->tickets" />
        </div>
    @endif

    <flux:modal :name="'ticket-quick-assign-'.$this->getId()" class="w-full max-w-md">
        <form wire:submit="saveAssign" class="space-y-6">
            <div>
                <flux:heading size="lg">Assign PIC</flux:heading>
                <flux:text class="mt-1">Hanya anggota aktif department saat ini yang dapat menjadi PIC.</flux:text>
            </div>
            @if ($assignTicketId)
                <div>
                    <livewire:user-picker wire:model="assignUserId" label="PIC" :required="true" :only="$this->assignableIds"
                        empty-hint="Tidak ada anggota department yang cocok." :key="'quick-assign-'.$assignTicketId" />
                    <flux:error name="assignUserId" />
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAssign">Assign</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
