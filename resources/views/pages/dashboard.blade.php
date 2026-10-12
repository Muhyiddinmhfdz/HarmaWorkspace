<?php

use App\Domain\Approval\Support\ApprovalInbox;
use App\Domain\System\Dashboard\NeedsAttention;
use App\Domain\Ticket\Support\TicketQueue;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\Task;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Role-aware dashboard (Phase 6): one page, sections shown by permission and
 * contextual role. Everyone sees their own work and what needs attention;
 * Project Managers see their projects, department leaders their department
 * work, and management (project.view-all) company progress and problems.
 */
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

    #[Computed]
    public function canSeeTickets(): bool
    {
        return Auth::user()->can('viewAny', Ticket::class);
    }

    #[Computed]
    public function canSeeProjects(): bool
    {
        return Auth::user()->can('viewAny', Project::class);
    }

    /**
     * Management view: company-wide project progress and problems.
     */
    #[Computed]
    public function isManagement(): bool
    {
        return Auth::user()->can('project.view-all');
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
     * Visible reports the user requested, created or is assigned to.
     *
     * @return Builder<Ticket>
     */
    private function myReportBase(): Builder
    {
        $user = Auth::user();

        return app(TicketQueue::class)->base($user)->where(fn (Builder $query) => $query
            ->where('tickets.requester_user_id', $user->id)
            ->orWhere('tickets.created_by', $user->id)
            ->orWhereHas('activeAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id)));
    }

    /**
     * Approvals waiting for the user's decision (0 = nothing shown).
     */
    #[Computed]
    public function pendingApprovals(): int
    {
        return Auth::user()->can('approval.decide') ? app(ApprovalInbox::class)->countFor(Auth::user()) : 0;
    }

    /**
     * Personal counters: what the user has to work on.
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function workCards(): array
    {
        $cards = [];

        if ($this->canSeeTasks) {
            $cards[] = ['label' => 'My Open Tasks', 'value' => $this->myOpenTasks()->count(), 'icon' => 'check-circle', 'url' => route('tasks.mine')];
            $cards[] = ['label' => 'Blocked', 'value' => Task::query()->visibleTo(Auth::user())->assignedTo(Auth::user())->blocked()->count(), 'icon' => 'no-symbol', 'url' => route('tasks.mine', ['view' => 'blocked']), 'tone' => 'red'];
        }

        if ($this->canSeeTickets) {
            $counts = app(TicketQueue::class)->counts($this->myReportBase(), ['open', 'waiting_for_me'], Auth::user());
            $cards[] = ['label' => 'My Open Reports', 'value' => $counts['open'], 'icon' => 'chat-bubble-left-right', 'url' => route('tickets.mine', ['view' => 'requested'])];
            $cards[] = ['label' => 'Reports Waiting', 'value' => $counts['waiting_for_me'], 'icon' => 'chat-bubble-left-ellipsis', 'url' => route('tickets.mine', ['view' => 'waiting_for_me']), 'tone' => 'amber'];
        }

        if ($this->pendingApprovals > 0) {
            $cards[] = ['label' => 'Pending My Approval', 'value' => $this->pendingApprovals, 'icon' => 'check-badge', 'url' => route('approvals.mine'), 'tone' => 'amber'];
        }

        return $cards;
    }

    /**
     * Company counters for management: progress and problems, not staff metrics.
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function managementCards(): array
    {
        if (! $this->isManagement) {
            return [];
        }

        $user = Auth::user();
        $cards = [
            ['label' => 'Active Projects', 'value' => $this->openProjects()->count(), 'icon' => 'folder', 'url' => route('projects.index')],
            ['label' => 'Projects At Risk', 'value' => $this->openProjects()->whereIn('health_status_id', $this->riskHealthIds())->count(), 'icon' => 'exclamation-triangle', 'url' => route('projects.index'), 'tone' => 'red'],
        ];

        if ($this->canSeeTickets) {
            $cards[] = ['label' => 'Open Reports', 'value' => app(TicketQueue::class)->counts(app(TicketQueue::class)->base($user), ['open'], $user)['open'], 'icon' => 'lifebuoy', 'url' => route('tickets.index')];
        }

        if ($this->canSeeTasks) {
            $cards[] = ['label' => 'Blocked Work', 'value' => Task::query()->visibleTo($user)->blocked()->count(), 'icon' => 'no-symbol', 'url' => route($user->can('task.view-all') ? 'tasks.index' : 'tasks.mine', ['view' => 'blocked']), 'tone' => 'red'];
        }

        return $cards;
    }

    /**
     * The summary row: management counters for management, personal work
     * for everyone else (4–5 cards, never a wall of numbers).
     *
     * @return list<array{label: string, value: int, icon: string, url: string, tone?: string}>
     */
    #[Computed]
    public function summary(): array
    {
        return $this->isManagement ? $this->managementCards : $this->workCards;
    }

    /**
     * Health states worse than the default (from the health master, never names).
     *
     * @return list<int>
     */
    private function riskHealthIds(): array
    {
        $defaultLevel = (int) ProjectHealthStatus::query()->where('is_default', true)->value('level');

        return array_map(intval(...), ProjectHealthStatus::query()
            ->where('represents_completion', false)
            ->where('level', '>', $defaultLevel)
            ->pluck('id')
            ->all());
    }

    /**
     * Next tasks to work on: blocked first, then by deadline.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function myTasks(): Collection
    {
        if (! $this->canSeeTasks) {
            return collect();
        }

        return $this->myOpenTasks()
            ->join('statuses as dash_status', 'dash_status.id', '=', 'tasks.status_id')
            ->select('tasks.*')
            ->with(['status', 'project:id,name'])
            ->orderByDesc('dash_status.is_blocked')
            ->orderByRaw('tasks.current_end IS NULL ASC')
            ->orderBy('tasks.current_end')
            ->limit(5)
            ->get();
    }

    /**
     * @return Builder<Project>
     */
    private function openProjects(): Builder
    {
        return Project::query()->visibleTo(Auth::user())->open();
    }

    /**
     * Whether the user manages (PM / owner) at least one open project.
     */
    #[Computed]
    public function isProjectManager(): bool
    {
        $userId = Auth::id();

        return $this->canSeeProjects && Project::query()->open()
            ->where(fn (Builder $query) => $query->where('project_manager_id', $userId)->orWhere('owner_id', $userId))
            ->exists();
    }

    /**
     * Project list for the user's role: company progress (management), managed
     * projects (Project Manager / Owner) or the projects the user takes part in.
     *
     * @return array{title: string, description: string, url: string, projects: Collection<int, Project>}|null
     */
    #[Computed]
    public function projectPanel(): ?array
    {
        if (! $this->canSeeProjects) {
            return null;
        }

        $user = Auth::user();
        $userId = $user->id;

        [$title, $description, $url, $query] = match (true) {
            $this->isManagement => ['Project Progress', 'Project aktif, yang paling bermasalah tampil di atas.', route('projects.index'), $this->openProjects()],
            $this->isProjectManager => ['Managed Projects', 'Project yang Anda kelola sebagai Project Manager atau Owner.', route('projects.index', ['tab' => 'mine']),
                $this->openProjects()->where(fn (Builder $query) => $query->where('project_manager_id', $userId)->orWhere('owner_id', $userId))],
            default => ['My Projects', 'Project tempat Anda terlibat.', route('projects.index', ['tab' => 'mine']), $this->openProjects()->involving($user)],
        };

        $projects = $query
            ->leftJoin('project_health_statuses as dash_health', 'dash_health.id', '=', 'projects.health_status_id')
            ->select('projects.*')
            ->with(['status', 'health', 'projectManager:id,name'])
            ->withCount([
                'tasks as blocked_tasks_count' => fn (Builder $tasks) => $tasks->blocked(),
                'tickets as open_reports_count' => fn (Builder $tickets) => $tickets->visibleTo($user)->open(),
            ])
            ->orderByDesc('dash_health.level')
            ->orderBy('projects.current_end')
            ->limit(6)
            ->get();

        return ['title' => $title, 'description' => $description, 'url' => $url, 'projects' => $projects];
    }

    /**
     * Inbox counters for the departments the user leads.
     *
     * @return array{departments: Collection<int, Department>, counts: array<string, int>, blocked_tasks: int}|null
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
            'blocked_tasks' => $this->canSeeTasks
                ? Task::query()->visibleTo(Auth::user())->whereIn('department_id', $departments->pluck('id'))->blocked()->count()
                : 0,
        ];
    }

    /**
     * @return list<array{kind: string, label: string, title: string, meta: string|null, url: string, tone: string}>
     */
    #[Computed]
    public function attention(): array
    {
        return app(NeedsAttention::class)->for(Auth::user(), $this->pendingApprovals, $this->leaderInbox['counts'] ?? null);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->greeting().', '.auth()->user()->name" :description="$this->isManagement ? 'Kondisi project dan kendala utama hari ini.' : 'Ini pekerjaan Anda dan hal yang perlu diperhatikan hari ini.'" />

    @if ($this->summary !== [])
        <section aria-label="{{ $this->isManagement ? 'Company Overview' : 'My Work' }}" class="mb-6">
            <h2 class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $this->isManagement ? 'Company Overview' : 'My Work' }}</h2>
            <div @class(['grid grid-cols-2 gap-3 sm:gap-4', 'lg:grid-cols-4' => count($this->summary) <= 4, 'lg:grid-cols-5' => count($this->summary) > 4])>
                @foreach ($this->summary as $card)
                    @php($alert = isset($card['tone']) && $card['value'] > 0)
                    <a href="{{ $card['url'] }}" wire:navigate wire:key="card-{{ $loop->index }}"
                       class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 sm:p-5">
                        <div class="flex items-center justify-between gap-2">
                            <flux:text class="truncate">{{ $card['label'] }}</flux:text>
                            <flux:icon :icon="$card['icon']" @class(['size-5 shrink-0', 'text-zinc-400' => ! $alert, 'text-red-500' => $alert && $card['tone'] === 'red', 'text-amber-500' => $alert && $card['tone'] === 'amber'])/>
                        </div>
                        <div @class(['mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl', 'text-red-600' => $alert && $card['tone'] === 'red', 'text-zinc-900' => ! ($alert && $card['tone'] === 'red')])>{{ number_format($card['value']) }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Needs attention: first on mobile, right column on desktop --}}
        <div class="min-w-0 space-y-6 lg:order-last">
            <flux:card class="p-0!">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <flux:heading size="lg">Needs Attention</flux:heading>
                </div>
                @forelse ($this->attention as $item)
                    <a href="{{ $item['url'] }}" wire:navigate wire:key="att-{{ $loop->index }}" class="flex gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-red-500' => $item['tone'] === 'red', 'bg-amber-500' => $item['tone'] === 'amber', 'bg-zinc-400' => $item['tone'] === 'zinc'])></span>
                        <span class="min-w-0">
                            <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $item['label'] }}</span>
                            <span class="block truncate text-sm font-medium text-zinc-900">{{ $item['title'] }}</span>
                            @if ($item['meta'])
                                <span class="block truncate text-xs text-zinc-500">{{ $item['meta'] }}</span>
                            @endif
                        </span>
                    </a>
                @empty
                    <x-ui.empty-state icon="check-badge" title="Semua aman" description="Tidak ada task terblokir, report yang menunggu Anda, atau approval tertunda." class="py-10" />
                @endforelse
            </flux:card>

            @if ($this->leaderInbox)
                @php($inbox = $this->leaderInbox)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-5 py-4">
                        <div class="min-w-0">
                            <flux:heading size="lg">Department Work</flux:heading>
                            <flux:text size="sm" class="truncate">{{ $inbox['departments']->pluck('name')->join(', ') }}</flux:text>
                        </div>
                        <flux:link :href="route('tickets.inbox')" wire:navigate class="shrink-0 text-sm">Department Inbox</flux:link>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-5">
                        @foreach ([
                            ['New Reports', $inbox['counts']['new'], route('tickets.inbox', ['view' => 'new']), false],
                            ['Unassigned', $inbox['counts']['unassigned'], route('tickets.inbox', ['view' => 'unassigned']), true],
                            ['In Review', $inbox['counts']['review'], route('tickets.inbox', ['view' => 'review']), false],
                            ['Blocked Tasks', $inbox['blocked_tasks'], route(auth()->user()->can('task.view-all') ? 'tasks.index' : 'tasks.mine', ['view' => 'blocked']), true],
                        ] as [$label, $value, $url, $alert])
                            <a href="{{ $url }}" wire:navigate wire:key="dw-{{ $loop->index }}" class="rounded-lg bg-zinc-50 px-3 py-2 hover:bg-zinc-100">
                                <div @class(['text-lg font-semibold tabular-nums', 'text-red-600' => $alert && $value > 0, 'text-zinc-900' => ! ($alert && $value > 0)])>{{ $value }}</div>
                                <div class="text-xs text-zinc-500">{{ $label }}</div>
                            </a>
                        @endforeach
                    </div>
                </flux:card>
            @endif
        </div>

        <div class="min-w-0 space-y-6 lg:col-span-2">
            @if ($this->canSeeTasks)
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <flux:heading size="lg">My Tasks</flux:heading>
                        <flux:link :href="route('tasks.mine')" wire:navigate class="text-sm">Lihat semua</flux:link>
                    </div>
                    @forelse ($this->myTasks as $task)
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="mt-{{ $task->id }}" class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50 sm:grid-cols-[1fr_8rem_7rem]">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">{{ $task->title }}</div>
                                <div class="flex min-w-0 items-center gap-2 text-xs text-zinc-500">
                                    <span class="truncate">{{ $task->project->name ?? 'Standalone' }}</span>
                                    @if ($task->status->is_blocked)
                                        <flux:badge size="sm" color="red" inset="top bottom">{{ $task->status->name }}</flux:badge>
                                    @endif
                                </div>
                            </div>
                            <div class="text-end sm:order-last">
                                @if ($task->current_end)
                                    <x-ui.deadline :date="$task->current_end" compact />
                                @else
                                    <span class="text-xs text-zinc-400">No deadline</span>
                                @endif
                            </div>
                            <x-ui.progress-bar :value="$task->progress_percentage" class="col-span-2 sm:col-span-1" />
                        </a>
                    @empty
                        <x-ui.empty-state icon="check-circle" title="Tidak ada task terbuka" description="Task yang Anda pegang sebagai PIC atau kolaborator akan muncul di sini." class="py-10" />
                    @endforelse
                </flux:card>
            @endif

            @if ($this->projectPanel)
                @php($panel = $this->projectPanel)
                <flux:card class="p-0!">
                    <div class="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4">
                        <div class="min-w-0">
                            <flux:heading size="lg">{{ $panel['title'] }}</flux:heading>
                            <flux:text size="sm">{{ $panel['description'] }}</flux:text>
                        </div>
                        <flux:link :href="$panel['url']" wire:navigate class="shrink-0 text-sm">Lihat semua</flux:link>
                    </div>
                    @forelse ($panel['projects'] as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="pp-{{ $project->id }}" class="block border-b border-zinc-100 px-5 py-3 last:border-b-0 hover:bg-zinc-50">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-zinc-900">{{ $project->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $project->number }} · PM {{ $project->projectManager->name }}</div>
                                </div>
                                <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                    <x-ui.master-badge :master="$project->status" />
                                    @if ($project->health && ! $project->health->is_default)
                                        <x-ui.master-badge :master="$project->health" />
                                    @endif
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-[1fr_auto] items-center gap-3 sm:grid-cols-[1fr_auto_auto]">
                                <x-ui.progress-bar :value="$project->progress_percentage" />
                                <x-ui.deadline :date="$project->current_end" compact class="text-end max-sm:hidden" />
                                <span class="text-end text-xs text-zinc-500">
                                    @if ($project->blocked_tasks_count > 0)
                                        <span class="text-red-600">{{ $project->blocked_tasks_count }} blocked</span> ·
                                    @endif
                                    {{ $project->open_reports_count }} open reports
                                </span>
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state icon="folder" title="Belum ada project aktif" description="Project tempat Anda menjadi Project Manager, Owner, atau anggota akan muncul di sini." class="py-10" />
                    @endforelse
                </flux:card>
            @endif
        </div>
    </div>
</div>
