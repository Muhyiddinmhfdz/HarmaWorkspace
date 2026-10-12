<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Ticket\Support\TicketQueue;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public function greeting(): string
    {
        return match (true) {
            now()->hour < 11 => 'Selamat pagi',
            now()->hour < 15 => 'Selamat siang',
            now()->hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }

    #[Computed]
    public function canSeeTasks(): bool
    {
        return Auth::user()->can('viewAny', Task::class);
    }

    /**
     * Open tasks the user is assigned to (PIC or collaborator), visible only.
     *
     * @return Builder<Task>
     */
    private function myOpenTasks(): Builder
    {
        return Task::query()->visibleTo(Auth::user())->assignedTo(Auth::user())->open();
    }

    /**
     * Actionable task counters (always the user's own tasks).
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function taskCards(): array
    {
        if (! $this->canSeeTasks) {
            return [];
        }

        return [
            ['label' => 'My Open Tasks', 'value' => $this->myOpenTasks()->count(), 'icon' => 'check-circle', 'url' => route('tasks.mine')],
            ['label' => 'Due Today', 'value' => $this->myOpenTasks()->whereDate('current_end', today())->count(), 'icon' => 'calendar', 'url' => route('tasks.mine', ['view' => 'due_today']), 'tone' => 'amber'],
            ['label' => 'Overdue', 'value' => $this->myOpenTasks()->overdue()->count(), 'icon' => 'clock', 'url' => route('tasks.mine', ['view' => 'overdue']), 'tone' => 'red'],
            ['label' => 'Blocked', 'value' => Task::query()->visibleTo(Auth::user())->assignedTo(Auth::user())->blocked()->count(), 'icon' => 'no-symbol', 'url' => route('tasks.mine', ['view' => 'blocked']), 'tone' => 'red'],
        ];
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function myTasks(): Collection
    {
        if (! $this->canSeeTasks) {
            return collect();
        }

        return $this->myOpenTasks()
            ->with(['status', 'project:id,name'])
            ->orderByRaw('current_end IS NULL ASC')
            ->orderBy('current_end')
            ->limit(6)
            ->get();
    }

    /**
     * Approvals waiting for the user's decision (0 = card hidden).
     */
    #[Computed]
    public function pendingApprovals(): int
    {
        return Auth::user()->can('approval.decide') ? app(\App\Domain\Approval\Support\ApprovalInbox::class)->countFor(Auth::user()) : 0;
    }

    #[Computed]
    public function canSeeTickets(): bool
    {
        return Auth::user()->can('viewAny', Ticket::class);
    }

    /**
     * Visible tickets the user requested, created or is assigned to.
     *
     * @return Builder<Ticket>
     */
    private function myTicketBase(): Builder
    {
        $user = Auth::user();

        return app(TicketQueue::class)->base($user)->where(fn (Builder $query) => $query
            ->where('tickets.requester_user_id', $user->id)
            ->orWhere('tickets.created_by', $user->id)
            ->orWhereHas('activeAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id)));
    }

    /**
     * The user's own ticket counters (one aggregate query).
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function ticketCards(): array
    {
        if (! $this->canSeeTickets) {
            return [];
        }

        $counts = app(TicketQueue::class)->counts($this->myTicketBase(), ['open', 'waiting_for_me', 'due_today', 'overdue'], Auth::user());

        return [
            ['label' => 'My Open Tickets', 'value' => $counts['open'], 'icon' => 'ticket', 'url' => route('tickets.mine', ['view' => 'requested'])],
            ['label' => 'Waiting for My Response', 'value' => $counts['waiting_for_me'], 'icon' => 'chat-bubble-left-ellipsis', 'url' => route('tickets.mine', ['view' => 'waiting_for_me']), 'tone' => 'amber'],
            ['label' => 'Tickets Due Today', 'value' => $counts['due_today'], 'icon' => 'calendar', 'url' => route('tickets.mine'), 'tone' => 'amber'],
            ['label' => 'Overdue Tickets', 'value' => $counts['overdue'], 'icon' => 'clock', 'url' => route('tickets.index', ['view' => 'overdue']), 'tone' => 'red'],
        ];
    }

    /**
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function myTickets(): Collection
    {
        if (! $this->canSeeTickets) {
            return collect();
        }

        $query = $this->myTicketBase()->select('tickets.*')->with(['status', 'priority', 'currentDepartment:id,name']);
        app(TicketQueue::class)->apply($query, 'open', Auth::user());
        app(TicketQueue::class)->orderActionable($query, Auth::user());

        return $query->limit(6)->get();
    }

    /**
     * Inbox counters for the departments the user leads.
     *
     * @return array{departments: Collection<int, Department>, counts: array<string, int>}|null
     */
    #[Computed]
    public function leaderInbox(): ?array
    {
        if (! $this->canSeeTickets) {
            return null;
        }

        $departments = Department::query()
            ->whereIn('id', Auth::user()->activeMemberships()->where('is_leader', true)->select('department_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($departments->isEmpty()) {
            return null;
        }

        $base = app(TicketQueue::class)->base(Auth::user())->whereIn('tickets.current_department_id', $departments->pluck('id'));

        return [
            'departments' => $departments,
            'counts' => app(TicketQueue::class)->counts($base, ['new', 'unassigned', 'in_progress', 'waiting', 'review', 'overdue'], Auth::user()),
        ];
    }

    /**
     * Company-wide ticket counters for ticket.view-all holders.
     *
     * @return array<string, int>|null
     */
    #[Computed]
    public function ticketOverview(): ?array
    {
        if (! Auth::user()->can('ticket.view-all')) {
            return null;
        }

        return app(TicketQueue::class)->counts(app(TicketQueue::class)->base(Auth::user()), ['open', 'critical', 'unassigned', 'overdue', 'review'], Auth::user());
    }

    #[Computed]
    public function canSeeProjects(): bool
    {
        return Auth::user()->can('viewAny', Project::class);
    }

    /**
     * @return Builder<Project>
     */
    private function openProjects(): Builder
    {
        return Project::query()->visibleTo(Auth::user())->open();
    }

    /**
     * Real counts only, for modules the user may view. Health cards come from
     * the health master (worse-than-default states) and are hidden when zero.
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function summary(): array
    {
        $user = Auth::user();
        $cards = [];

        if ($this->canSeeProjects) {
            $cards[] = ['label' => 'Active Projects', 'value' => $this->openProjects()->count(), 'icon' => 'folder', 'url' => route('projects.index')];

            $defaultLevel = (int) ProjectHealthStatus::query()->where('is_default', true)->value('level');
            $counts = $this->openProjects()->selectRaw('health_status_id, count(*) as aggregate')->groupBy('health_status_id')->pluck('aggregate', 'health_status_id');

            ProjectHealthStatus::query()->active()->where('represents_completion', false)->where('level', '>', $defaultLevel)->orderByDesc('level')->get()
                ->each(function (ProjectHealthStatus $health) use (&$cards, $counts): void {
                    if (($counts[$health->id] ?? 0) > 0) {
                        $cards[] = ['label' => $health->name.' Projects', 'value' => (int) $counts[$health->id], 'icon' => 'exclamation-triangle', 'url' => route('projects.index', ['tab' => 'health-'.$health->id]), 'tone' => $health->color];
                    }
                });
        }

        if ($user->can('task.view-all')) {
            $cards[] = ['label' => 'Overdue Tasks (all)', 'value' => Task::query()->overdue()->count(), 'icon' => 'clock', 'url' => route('tasks.index', ['view' => 'overdue']), 'tone' => 'red'];
            $cards[] = ['label' => 'Blocked Tasks (all)', 'value' => Task::query()->blocked()->count(), 'icon' => 'no-symbol', 'url' => route('tasks.index', ['view' => 'blocked']), 'tone' => 'red'];
        }

        if ($user->can('user.view') && count($cards) < 4) {
            $cards[] = ['label' => 'Active Members', 'value' => User::query()->active()->count(), 'icon' => 'users', 'url' => route('admin.users.index', ['status' => 'active'])];
        }

        if ($user->can('client.view') && count($cards) < 4) {
            $cards[] = ['label' => 'Active Clients', 'value' => Client::query()->active()->count(), 'icon' => 'briefcase', 'url' => route('clients.index', ['status' => 'active'])];
        }

        if ($user->can('department.view') && count($cards) < 4) {
            $cards[] = ['label' => 'Departments', 'value' => Department::query()->active()->count(), 'icon' => 'building-office-2', 'url' => route('admin.departments.index', ['status' => 'active'])];
        }

        return array_slice($cards, 0, 4);
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function myProjects(): Collection
    {
        if (! $this->canSeeProjects) {
            return collect();
        }

        return $this->openProjects()->involving(Auth::user())
            ->with(['status', 'health'])
            ->orderBy('current_end')
            ->limit(5)
            ->get(['id', 'number', 'name', 'status_id', 'health_status_id', 'progress_percentage', 'current_end', 'completed_at', 'project_manager_id', 'owner_id']);
    }

    /**
     * Visible open projects due within 30 days, including overdue ones.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function upcomingDeadlines(): Collection
    {
        if (! $this->canSeeProjects) {
            return collect();
        }

        return $this->openProjects()
            ->where('current_end', '<=', today()->addDays(30))
            ->with('projectManager:id,name')
            ->orderBy('current_end')
            ->limit(5)
            ->get(['id', 'number', 'name', 'current_end', 'completed_at', 'project_manager_id']);
    }

    /**
     * Next milestones of projects the user manages (contextual PM / owner).
     *
     * @return Collection<int, ProjectMilestone>
     */
    #[Computed]
    public function managedMilestones(): Collection
    {
        $userId = Auth::id();

        return ProjectMilestone::query()
            ->whereNull('actual_date')
            ->where('current_due_date', '<=', today()->addDays(30))
            ->whereHas('project', fn (Builder $project) => $project->open()->where(fn (Builder $query) => $query->where('project_manager_id', $userId)->orWhere('owner_id', $userId)))
            ->with('project:id,number,name')
            ->orderBy('current_due_date')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, DepartmentMembership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return Auth::user()->activeMemberships()
            ->with(['department:id,code,name', 'position:id,name'])
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        if (! Auth::user()->can('audit.view')) {
            return collect();
        }

        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->where('event', '!=', 'workflow.transition_executed')
            ->with('causer:id,name,username')
            ->latest('id')
            ->limit(6)
            ->get();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->greeting().', '.auth()->user()->name" description="Berikut ringkasan workspace Anda hari ini." />

    @if ($this->pendingApprovals > 0)
        <a href="{{ route('approvals.mine') }}" wire:navigate class="mb-4 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 transition hover:border-amber-300">
            <div class="flex items-center gap-3">
                <flux:icon.check-badge class="size-6 text-amber-600" />
                <div>
                    <div class="text-sm font-medium text-zinc-900">Pending My Approval</div>
                    <div class="text-xs text-zinc-600">Permintaan yang menunggu keputusan Anda.</div>
                </div>
            </div>
            <span class="text-2xl font-semibold tabular-nums text-zinc-900">{{ $this->pendingApprovals }}</span>
        </a>
    @endif

    @if ($this->taskCards !== [])
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->taskCards as $card)
                <a href="{{ $card['url'] }}" wire:navigate wire:key="task-card-{{ $loop->index }}"
                   class="group rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300">
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $card['label'] }}</flux:text>
                        <flux:icon :icon="$card['icon']" @class(['size-5', 'text-zinc-400' => ! isset($card['tone']) || $card['value'] === 0, 'text-amber-500' => ($card['tone'] ?? null) === 'amber' && $card['value'] > 0, 'text-red-500' => ($card['tone'] ?? null) === 'red' && $card['value'] > 0]) />
                    </div>
                    <div @class(['mt-3 text-3xl font-semibold tracking-tight', 'text-red-600' => ($card['tone'] ?? null) === 'red' && $card['value'] > 0, 'text-zinc-900' => ($card['tone'] ?? null) !== 'red' || $card['value'] === 0])>{{ number_format($card['value']) }}</div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->ticketCards !== [])
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->ticketCards as $card)
                <a href="{{ $card['url'] }}" wire:navigate wire:key="ticket-card-{{ $loop->index }}"
                   class="group rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300">
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $card['label'] }}</flux:text>
                        <flux:icon :icon="$card['icon']" @class(['size-5', 'text-zinc-400' => ! isset($card['tone']) || $card['value'] === 0, 'text-amber-500' => ($card['tone'] ?? null) === 'amber' && $card['value'] > 0, 'text-red-500' => ($card['tone'] ?? null) === 'red' && $card['value'] > 0]) />
                    </div>
                    <div @class(['mt-3 text-3xl font-semibold tracking-tight', 'text-red-600' => ($card['tone'] ?? null) === 'red' && $card['value'] > 0, 'text-zinc-900' => ($card['tone'] ?? null) !== 'red' || $card['value'] === 0])>{{ number_format($card['value']) }}</div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->summary !== [])
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->summary as $card)
                <a href="{{ $card['url'] }}" wire:navigate wire:key="summary-{{ $loop->index }}"
                   class="group rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300">
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $card['label'] }}</flux:text>
                        <flux:icon :icon="$card['icon']" @class(['size-5', 'text-zinc-400 group-hover:text-zinc-600' => ! isset($card['tone']), 'text-amber-500' => ($card['tone'] ?? null) === 'amber', 'text-red-500' => ($card['tone'] ?? null) === 'red', 'text-orange-500' => ($card['tone'] ?? null) === 'orange']) />
                    </div>
                    <div class="mt-3 text-3xl font-semibold tracking-tight text-zinc-900">{{ number_format($card['value']) }}</div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($this->canSeeTasks)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">My Tasks</flux:heading>
                        <flux:link :href="route('tasks.mine')" wire:navigate class="text-sm">Lihat semua</flux:link>
                    </div>
                    @forelse ($this->myTasks as $task)
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="mt-{{ $task->id }}" class="grid grid-cols-[1fr_auto] items-center gap-4 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50 sm:grid-cols-[1fr_7rem_8rem]">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $task->title }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $task->number }} · {{ $task->project->name ?? 'Standalone' }}</div>
                            </div>
                            <x-ui.progress-bar :value="$task->progress_percentage" class="max-sm:hidden" />
                            @if ($task->current_end)
                                <x-ui.deadline :date="$task->current_end" class="text-end" />
                            @else
                                <span class="text-end text-xs text-zinc-400">No deadline</span>
                            @endif
                        </a>
                    @empty
                        <x-ui.empty-state icon="check-circle" title="Tidak ada task terbuka" description="Task yang Anda pegang sebagai PIC atau kolaborator akan muncul di sini." class="py-10" />
                    @endforelse
                </flux:card>
            @endif

            @if ($this->canSeeTickets)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">My Tickets</flux:heading>
                        <flux:link :href="route('tickets.mine')" wire:navigate class="text-sm">Lihat semua</flux:link>
                    </div>
                    @forelse ($this->myTickets as $ticket)
                        <a href="{{ route('tickets.show', $ticket) }}" wire:navigate wire:key="mtk-{{ $ticket->id }}" class="grid grid-cols-[1fr_auto] items-center gap-4 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50 sm:grid-cols-[1fr_8rem_8rem]">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $ticket->title }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $ticket->number }} · {{ $ticket->currentDepartment->name }}</div>
                            </div>
                            <x-ui.master-badge :master="$ticket->status" class="max-sm:hidden" />
                            @if ($ticket->current_due_at)
                                <x-ui.deadline :date="$ticket->current_due_at" class="text-end" />
                            @else
                                <span class="text-end text-xs text-zinc-400">No deadline</span>
                            @endif
                        </a>
                    @empty
                        <x-ui.empty-state icon="ticket" title="Tidak ada ticket aktif" description="Ticket yang Anda ajukan atau tangani akan muncul di sini." class="py-10" />
                    @endforelse
                </flux:card>
            @endif

            @if ($this->canSeeProjects)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">My Projects</flux:heading>
                        <flux:link :href="route('projects.index', ['tab' => 'mine'])" wire:navigate class="text-sm">Lihat semua</flux:link>
                    </div>
                    @forelse ($this->myProjects as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="mp-{{ $project->id }}" class="grid grid-cols-[1fr_auto] items-center gap-4 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50 sm:grid-cols-[1fr_8rem_8rem]">
                            <div class="min-w-0">
                                <div class="font-mono text-xs text-zinc-500">{{ $project->number }}</div>
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $project->name }}</div>
                                <div class="mt-1 flex gap-1">
                                    <x-ui.master-badge :master="$project->status" />
                                    <x-ui.master-badge :master="$project->health" />
                                </div>
                            </div>
                            <x-ui.progress-bar :value="$project->progress_percentage" class="max-sm:hidden" />
                            <x-ui.deadline :date="$project->current_end" class="text-end" />
                        </a>
                    @empty
                        <x-ui.empty-state icon="folder" title="Belum ada project yang Anda ikuti" description="Project tempat Anda menjadi Project Manager, Owner, atau anggota akan muncul di sini." class="py-10" />
                    @endforelse
                </flux:card>

                <flux:card class="p-0!">
                    <div class="border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Upcoming Project Deadlines</flux:heading>
                        <flux:text size="sm">30 hari ke depan, termasuk yang sudah terlambat.</flux:text>
                    </div>
                    @forelse ($this->upcomingDeadlines as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="dl-{{ $project->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $project->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $project->number }} · PM {{ $project->projectManager->name }}</div>
                            </div>
                            <x-ui.deadline :date="$project->current_end" class="shrink-0 text-end" />
                        </a>
                    @empty
                        <x-ui.empty-state icon="calendar-days" title="Tidak ada deadline dalam 30 hari" class="py-10" />
                    @endforelse
                </flux:card>
            @endif

            @can('audit.view')
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Recent Activity</flux:heading>
                        <flux:link :href="route('admin.audit.index')" wire:navigate class="text-sm">Lihat audit log</flux:link>
                    </div>
                    @forelse ($this->recentActivity as $activity)
                        <div wire:key="activity-{{ $activity->id }}" class="flex items-start gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0">
                            @if ($activity->causer)
                                <flux:avatar size="xs" :name="$activity->causer->name" :initials="$activity->causer->initials()" />
                            @else
                                <flux:avatar size="xs" icon="cog-6-tooth" />
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-zinc-800">
                                    <span class="font-medium">{{ $activity->causer?->name ?? 'System' }}</span>
                                    {{ \Illuminate\Support\Str::lcfirst($activity->description) }}
                                </div>
                                <flux:text size="sm">{{ $activity->created_at->diffForHumans() }}</flux:text>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="clock" title="Belum ada aktivitas" description="Aktivitas penting akan tercatat di sini." />
                    @endforelse
                </flux:card>
            @endcan
        </div>

        <div class="space-y-6">
            @if ($this->leaderInbox)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <div class="min-w-0">
                            <flux:heading size="lg">Department Inbox</flux:heading>
                            <flux:text size="sm" class="truncate">{{ $this->leaderInbox['departments']->pluck('name')->join(', ') }}</flux:text>
                        </div>
                        <flux:link :href="route('tickets.inbox')" wire:navigate class="shrink-0 text-sm">Buka</flux:link>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-5">
                        @foreach (['new' => 'New', 'unassigned' => 'Unassigned', 'in_progress' => 'In Progress', 'waiting' => 'Waiting Information', 'review' => 'In Review', 'overdue' => 'Overdue'] as $queue => $label)
                            <a href="{{ route('tickets.inbox', ['view' => $queue]) }}" wire:navigate wire:key="li-{{ $queue }}" class="rounded-lg bg-zinc-50 px-3 py-2 hover:bg-zinc-100">
                                <div @class(['text-lg font-semibold tabular-nums', 'text-red-600' => $queue === 'overdue' && $this->leaderInbox['counts'][$queue] > 0, 'text-zinc-900' => $queue !== 'overdue' || $this->leaderInbox['counts'][$queue] === 0])>{{ $this->leaderInbox['counts'][$queue] }}</div>
                                <div class="text-xs text-zinc-500">{{ $label }}</div>
                            </a>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            @if ($this->ticketOverview)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Ticket Overview</flux:heading>
                        <flux:link :href="route('tickets.index')" wire:navigate class="text-sm">Tickets</flux:link>
                    </div>
                    @foreach (['open' => 'Open Tickets', 'critical' => 'Critical Tickets', 'unassigned' => 'Unassigned Tickets', 'overdue' => 'Overdue Tickets', 'review' => 'Tickets In Review'] as $queue => $label)
                        <a href="{{ route('tickets.index', ['view' => $queue === 'critical' ? 'open' : $queue]) }}" wire:navigate wire:key="to-{{ $queue }}" class="flex items-center justify-between border-b border-zinc-100 px-5 py-2.5 text-sm last:border-b-0 hover:bg-zinc-50">
                            <span class="text-zinc-700">{{ $label }}</span>
                            <span @class(['font-semibold tabular-nums', 'text-red-600' => in_array($queue, ['critical', 'overdue'], true) && $this->ticketOverview[$queue] > 0, 'text-zinc-900' => ! in_array($queue, ['critical', 'overdue'], true) || $this->ticketOverview[$queue] === 0])>{{ $this->ticketOverview[$queue] }}</span>
                        </a>
                    @endforeach
                </flux:card>
            @endif

            @if ($this->managedMilestones->isNotEmpty())
                <flux:card class="p-0!">
                    <div class="border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">Upcoming Milestones</flux:heading>
                        <flux:text size="sm">Project yang Anda kelola.</flux:text>
                    </div>
                    @foreach ($this->managedMilestones as $milestone)
                        <a href="{{ route('projects.show', ['project' => $milestone->project_id, 'tab' => 'milestones']) }}" wire:navigate wire:key="ms-{{ $milestone->id }}" class="block border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                            <div class="truncate text-sm font-medium text-zinc-900">{{ $milestone->name }}</div>
                            <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                                <span class="truncate">{{ $milestone->project->name }}</span>
                                <span class="shrink-0 {{ $milestone->isOverdue() ? 'text-red-600' : '' }}">{{ $milestone->current_due_date->translatedFormat('d M') }}</span>
                            </div>
                        </a>
                    @endforeach
                </flux:card>
            @endif

            <flux:card class="h-fit p-0!">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <flux:heading size="lg">My Organization</flux:heading>
                </div>
                @forelse ($this->memberships as $membership)
                    <div wire:key="membership-{{ $membership->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-800">{{ $membership->department->name }}</div>
                            <flux:text size="sm">{{ $membership->position?->name ?? 'Tanpa position' }}</flux:text>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            @if ($membership->is_primary)
                                <flux:badge size="sm" color="blue">Primary</flux:badge>
                            @endif
                            @if ($membership->is_leader)
                                <flux:badge size="sm" color="amber">Leader</flux:badge>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="building-office-2" title="Belum ada department" description="Hubungi administrator untuk menambahkan Anda ke department." />
                @endforelse
            </flux:card>

            <div class="rounded-xl border border-dashed border-zinc-300 p-5">
                <flux:heading>Coming soon</flux:heading>
                <flux:text class="mt-1">Workflow dan Approval (Phase 5) akan muncul di dashboard ini.</flux:text>
            </div>
        </div>
    </div>
</div>
