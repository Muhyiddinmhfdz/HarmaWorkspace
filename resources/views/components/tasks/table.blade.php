<?php

use App\Domain\Task\Actions\UpdateTaskProgress;
use App\Enums\MasterScope;
use App\Enums\TaskAssignmentRole;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
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
 * Shared task list: project Tasks tab (mode=project), global list (all) and
 * My Tasks (mine). Every query starts from Task::visibleTo().
 *
 * Phase 6: a few primary quick views with counts, the rest under "More";
 * compact columns (Task, PIC, Status, Progress, Deadline) with project,
 * priority and department as secondary information.
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

    #[Url(except: '')]
    public string $statusId = '';

    #[Url(except: '')]
    public string $priorityId = '';

    #[Url(except: '')]
    public string $departmentId = '';

    #[Url(except: '')]
    public string $filterProjectId = '';

    #[Url(except: '')]
    public string $milestoneId = '';

    #[Url(except: '')]
    public string $picId = '';

    #[Url(except: '')]
    public string $deadline = '';

    public bool $showFilters = false;

    #[Locked]
    public ?int $progressTaskId = null;

    public int $progress = 0;

    public function mount(string $mode = 'all', ?int $projectId = null): void
    {
        $this->mode = in_array($mode, ['project', 'all', 'mine'], true) ? $mode : 'all';
        $this->projectId = $this->mode === 'project' ? $projectId : null;

        if ($this->projectId !== null) {
            $this->authorize('view', Project::query()->findOrFail($this->projectId));
        } else {
            $this->authorize('viewAny', Task::class);
        }

        if ($this->quick === '' || ! array_key_exists($this->quick, $this->primaryViews() + $this->moreViews())) {
            $this->quick = $this->defaultQuick();
        }
    }

    /**
     * Primary quick views per mode (always visible, with counts).
     *
     * @return array<string, string>
     */
    public function primaryViews(): array
    {
        return match ($this->mode) {
            'mine' => ['attention' => 'Needs Attention', 'in_progress' => 'In Progress', 'blocked' => 'Blocked', 'upcoming' => 'Upcoming', 'completed' => 'Completed'],
            'project' => ['all' => 'All', 'mine' => 'My Tasks', 'in_progress' => 'In Progress', 'blocked' => 'Blocked', 'completed' => 'Done'],
            default => ['open' => 'Open', 'mine' => 'My Tasks', 'in_progress' => 'In Progress', 'blocked' => 'Blocked', 'completed' => 'Done'],
        };
    }

    /**
     * Secondary quick views under "More" (kept for links and power users).
     *
     * @return array<string, string>
     */
    public function moreViews(): array
    {
        return match ($this->mode) {
            'mine' => ['assigned' => 'All open (PIC)', 'collaborating' => 'Collaborating', 'due_today' => 'Due today', 'overdue' => 'Overdue'],
            'project' => ['open' => 'Open', 'overdue' => 'Overdue'],
            default => ['overdue' => 'Overdue', 'due_today' => 'Due today', 'all' => 'All'],
        };
    }

    /**
     * My Tasks opens on what needs attention, otherwise on work in progress.
     */
    private function defaultQuick(): string
    {
        if ($this->mode !== 'mine') {
            return $this->mode === 'project' ? 'all' : 'open';
        }

        foreach (['attention', 'in_progress', 'upcoming'] as $candidate) {
            $query = $this->base();
            $this->applyQuick($query, $candidate);

            if ($query->exists()) {
                return $candidate;
            }
        }

        return 'attention';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['quick', 'search', 'statusId', 'priorityId', 'departmentId', 'filterProjectId', 'milestoneId', 'picId', 'deadline'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'statusId', 'priorityId', 'departmentId', 'filterProjectId', 'milestoneId', 'picId', 'deadline');
        $this->resetPage();
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([$this->statusId, $this->priorityId, $this->departmentId, $this->filterProjectId, $this->milestoneId, $this->picId, $this->deadline]));
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->projectId ? Project::query()->with('status')->find($this->projectId) : null;
    }

    /**
     * @return Builder<Task>
     */
    private function base(): Builder
    {
        $user = auth()->user();

        return Task::query()
            ->visibleTo($user)
            ->when($this->projectId !== null, fn (Builder $query) => $query->where('project_id', $this->projectId))
            ->when($this->mode === 'mine', fn (Builder $query) => $query->assignedTo($user));
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyQuick(Builder $query, string $quick): void
    {
        $user = auth()->user();

        match ($quick) {
            'open' => $query->open(),
            'mine', 'assigned' => $query->open()->assignedTo($user, $quick === 'assigned' ? TaskAssignmentRole::Pic : null),
            'collaborating' => $query->open()->assignedTo($user, TaskAssignmentRole::Collaborator),
            'due_today' => $query->open()->whereDate('current_end', today()),
            'in_progress' => $query->inProgress(),
            'upcoming' => $query->notStarted(),
            // Blocked, overdue or due today.
            'attention' => $query->where(fn (Builder $attention) => $attention
                ->blocked()
                ->orWhere(fn (Builder $late) => $late->open()->whereNotNull('current_end')->whereDate('current_end', '<=', today()))),
            'blocked' => $query->blocked(),
            'overdue' => $query->overdue(),
            'completed' => $query->whereHas('status', fn (Builder $status) => $status->where('counts_as_completed', true)),
            default => null,
        };
    }

    /**
     * Primary quick views with real counts (one count per view). Secondary
     * views are listed without counts, except the selected one.
     *
     * @return list<array{key: string, label: string, count: int|null}>
     */
    #[Computed]
    public function quickViews(): array
    {
        $views = [];

        foreach ($this->primaryViews() as $key => $label) {
            $views[] = ['key' => $key, 'label' => $label, 'count' => $this->countFor($key)];
        }

        return $views;
    }

    private function countFor(string $quick): int
    {
        $query = $this->base();
        $this->applyQuick($query, $quick);

        return $query->count();
    }

    /**
     * Priorities above the default level get a small badge in the task cell;
     * normal and low priorities stay quiet (levels from the master, no names).
     */
    #[Computed]
    public function highPriorityLevel(): int
    {
        $levels = Priority::query()->forScope(MasterScope::Task)->active()->orderBy('level')->pluck('level')->all();

        return isset($levels[2]) ? (int) $levels[2] : PHP_INT_MAX;
    }

    /**
     * Whether the department column helps (multi-department projects, or
     * lists that span projects show department in the task cell instead).
     */
    #[Computed]
    public function showDepartment(): bool
    {
        return $this->project !== null && $this->project->departments()->count() > 1;
    }

    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $query = $this->base()
            ->join('statuses as sort_status', 'sort_status.id', '=', 'tasks.status_id')
            ->select('tasks.*')
            ->with([
            'status',
            'priority',
            'department:id,name',
            'project:id,number,name,project_manager_id',
            'milestone:id,name',
            'picAssignment.user:id,name,username',
        ])->withCount([
            'subtasks',
            'subtasks as completed_subtasks_count' => fn (Builder $query) => $query->where('is_completed', true),
        ]);

        $this->applyQuick($query, $this->quick);

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';

        $query
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('tasks.number', 'like', $pattern)
                ->orWhere('tasks.title', 'like', $pattern)
                ->orWhereHas('picAssignment.user', fn (Builder $user) => $user->where('name', 'like', $pattern))))
            ->when($this->statusId !== '', fn (Builder $query) => $query->where('tasks.status_id', (int) $this->statusId))
            ->when($this->priorityId !== '', fn (Builder $query) => $query->where('priority_id', (int) $this->priorityId))
            ->when($this->departmentId !== '', fn (Builder $query) => $query->where('department_id', (int) $this->departmentId))
            ->when($this->filterProjectId === 'standalone', fn (Builder $query) => $query->whereNull('project_id'))
            ->when(ctype_digit($this->filterProjectId), fn (Builder $query) => $query->where('project_id', (int) $this->filterProjectId))
            ->when($this->milestoneId !== '', fn (Builder $query) => $query->where('milestone_id', (int) $this->milestoneId))
            ->when($this->picId !== '', fn (Builder $query) => $query->whereHas('picAssignment', fn (Builder $pic) => $pic->where('user_id', (int) $this->picId)))
            ->when($this->deadline === '7', fn (Builder $query) => $query->whereBetween('current_end', [today(), today()->addDays(7)]))
            ->when($this->deadline === 'none', fn (Builder $query) => $query->whereNull('current_end'));

        // Actionable order: overdue, due today, upcoming, no deadline, finished last.
        $query->orderByRaw('sort_status.is_terminal ASC')
            ->orderByRaw('tasks.current_end IS NULL ASC')
            ->orderBy('tasks.current_end')
            ->orderByDesc('tasks.id');

        return $query->paginate(15);
    }

    /**
     * @return array{statuses: Collection<int, Status>, priorities: Collection<int, Priority>, departments: Collection<int, Department>, projects: Collection<int, Project>, milestones: Collection<int, \App\Models\ProjectMilestone>, people: Collection<int, \App\Models\User>}
     */
    #[Computed]
    public function filterOptions(): array
    {
        $project = $this->project;

        return [
            'statuses' => Status::query()->forScope(MasterScope::Task)->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Task)->orderByDesc('level')->get(['id', 'name']),
            'departments' => $project
                ? Department::query()->whereIn('id', $project->departments()->select('department_id'))->orderBy('name')->get(['id', 'name'])
                : Department::query()->whereIn('id', $this->base()->whereNotNull('department_id')->select('department_id'))->orderBy('name')->get(['id', 'name']),
            'projects' => $project ? collect() : Project::query()->whereIn('id', $this->base()->whereNotNull('project_id')->select('project_id'))->orderBy('name')->get(['id', 'number', 'name']),
            'milestones' => $project ? $project->milestones()->orderBy('current_due_date')->get(['id', 'name']) : collect(),
            'people' => \App\Models\User::query()
                ->whereIn('id', \App\Models\TaskAssignment::query()->whereNull('ended_at')->where('role', TaskAssignmentRole::Pic->value)->whereIn('task_id', $this->base()->select('tasks.id'))->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * Department leaderships of the current user, loaded once to evaluate row
     * actions without per-row queries. The backend re-authorizes every action.
     *
     * @return list<int>
     */
    #[Computed]
    public function ledDepartmentIds(): array
    {
        return array_map(intval(...), auth()->user()->activeMemberships()->where('is_leader', true)->pluck('department_id')->all());
    }

    public function canQuickUpdate(Task $task): bool
    {
        $user = auth()->user();

        if ($task->status->is_terminal || ($task->project_id !== null && $this->project?->isArchived())) {
            return false;
        }

        return $task->picAssignment?->user_id === $user->id
            || $user->can('task.manage-progress')
            || ($task->project !== null && $task->project->project_manager_id === $user->id)
            || ($task->project_id === null && $task->created_by === $user->id)
            || in_array($task->department_id, $this->ledDepartmentIds, true);
    }

    /**
     * Archived/finished projects never offer task creation, also for the
     * Super Admin bypass (the action rejects it anyway).
     */
    public function canCreate(): bool
    {
        $project = $this->project;

        if ($project !== null && ($project->isArchived() || $project->status->is_terminal)) {
            return false;
        }

        return auth()->user()->can('create', [Task::class, $project]);
    }

    public function openProgress(int $taskId): void
    {
        $task = $this->base()->findOrFail($taskId);
        $this->authorize('work', $task);

        $this->progressTaskId = $task->id;
        $this->progress = $task->progress_percentage;
        Flux::modal('task-quick-progress-'.$this->getId())->show();
    }

    public function saveProgress(UpdateTaskProgress $updateProgress): void
    {
        $task = $this->base()->findOrFail((int) $this->progressTaskId);
        $this->authorize('work', $task);
        $this->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $updateProgress->handle($task, $this->progress, auth()->user());

        Flux::modal('task-quick-progress-'.$this->getId())->close();
        $this->notify('success', 'Progress Task '.$task->number.' diperbarui menjadi '.$this->progress.'%.');
        unset($this->tasks, $this->quickViews);
        $this->dispatch('project-updated');
    }
}; ?>

<div>
    {{-- Quick views: primary with counts, the rest under More --}}
    <div class="mb-4 flex items-end gap-1 border-b border-zinc-200">
        <nav class="-mb-px flex min-w-0 flex-1 gap-1 overflow-x-auto" aria-label="Task views">
            @foreach ($this->quickViews as $view)
                <button type="button" wire:key="qv-{{ $view['key'] }}" wire:click="$set('quick', '{{ $view['key'] }}')"
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
        <flux:dropdown position="bottom" align="end">
            <button type="button" @class([
                '-mb-px flex shrink-0 items-center gap-1 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                'border-accent text-zinc-900' => array_key_exists($quick, $this->moreViews()),
                'border-transparent text-zinc-500 hover:text-zinc-800' => ! array_key_exists($quick, $this->moreViews()),
            ])>
                {{ $this->moreViews()[$quick] ?? 'More' }}
                <flux:icon.chevron-down variant="micro" />
            </button>
            <flux:menu>
                @foreach ($this->moreViews() as $key => $label)
                    <flux:menu.item wire:key="mv-{{ $key }}" wire:click="$set('quick', '{{ $key }}')">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- Search & filters --}}
    <div class="mb-4 space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search number, title, PIC..." clearable aria-label="Search tasks" />
            </div>
            <div class="flex items-center gap-2">
                <flux:button icon="adjustments-horizontal" wire:click="$toggle('showFilters')" :variant="$showFilters ? 'filled' : 'outline'">
                    More Filters
                    @if ($this->activeFilterCount() > 0)
                        <flux:badge size="sm" color="indigo" class="ms-1">{{ $this->activeFilterCount() }}</flux:badge>
                    @endif
                </flux:button>
                @if ($this->activeFilterCount() > 0 || $search !== '')
                    <flux:button variant="ghost" wire:click="resetFilters">Reset</flux:button>
                @endif
            </div>
            <flux:spacer />
            @if ($this->canCreate())
                <flux:button variant="primary" icon="plus" :href="route('tasks.create', $projectId ? ['project' => $projectId] : [])" wire:navigate>New Task</flux:button>
            @endif
        </div>

        @if ($showFilters)
            <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <flux:select wire:model.live="departmentId" label="Department">
                    <flux:select.option value="">All departments</flux:select.option>
                    @foreach ($this->filterOptions['departments'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fd-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @unless ($mode === 'mine')
                    <flux:select wire:model.live="picId" label="PIC">
                        <flux:select.option value="">Anyone</flux:select.option>
                        @foreach ($this->filterOptions['people'] as $option)
                            <flux:select.option :value="$option->id" wire:key="fpic-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endunless
                <flux:select wire:model.live="priorityId" label="Priority">
                    <flux:select.option value="">All priority</flux:select.option>
                    @foreach ($this->filterOptions['priorities'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fp-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($projectId)
                    <flux:select wire:model.live="milestoneId" label="Milestone">
                        <flux:select.option value="">All milestones</flux:select.option>
                        @foreach ($this->filterOptions['milestones'] as $option)
                            <flux:select.option :value="$option->id" wire:key="fm-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model.live="filterProjectId" label="Project">
                        <flux:select.option value="">All projects</flux:select.option>
                        <flux:select.option value="standalone">Standalone tasks</flux:select.option>
                        @foreach ($this->filterOptions['projects'] as $option)
                            <flux:select.option :value="$option->id" wire:key="fpr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:select wire:model.live="statusId" label="Status">
                    <flux:select.option value="">All status</flux:select.option>
                    @foreach ($this->filterOptions['statuses'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fs-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="deadline" label="Deadline">
                    <flux:select.option value="">Any time</flux:select.option>
                    <flux:select.option value="7">Next 7 days</flux:select.option>
                    <flux:select.option value="none">No deadline</flux:select.option>
                </flux:select>
            </div>
        @endif
    </div>

    @if ($this->tasks->isEmpty())
        <x-ui.table-card>
            @if ($search !== '' || $this->activeFilterCount() > 0)
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada task yang cocok" description="Coba ubah kata kunci atau filter.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @elseif ($mode === 'project')
                <x-ui.empty-state icon="check-circle" title="Belum ada Task." description="Tambahkan Task untuk mulai membagi pekerjaan Project.">
                    @if ($this->canCreate())
                        <flux:button variant="primary" icon="plus" size="sm" :href="route('tasks.create', ['project' => $projectId])" wire:navigate>New Task</flux:button>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="check-circle" title="Tidak ada task di tampilan ini." description="{{ $mode === 'mine' ? 'Task yang Anda pegang sebagai PIC atau kolaborator akan muncul di sini.' : 'Task yang dapat Anda lihat akan muncul di sini.' }}" />
            @endif
        </x-ui.table-card>
    @else
        <x-ui.table-card class="hidden md:block">
            <flux:table :paginate="$this->tasks" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column>Task</flux:table.column>
                    <flux:table.column class="max-lg:hidden">PIC</flux:table.column>
                    @if ($this->showDepartment)
                        <flux:table.column class="max-xl:hidden">Department</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="w-32">Progress</flux:table.column>
                    <flux:table.column>Deadline</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tasks as $task)
                        <flux:table.row :key="$task->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('tasks.show', $task) }}')">
                            <flux:table.cell class="max-w-96">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate x-on:click.stop class="block truncate font-medium text-zinc-900 hover:underline">{{ $task->title }}</a>
                                <div class="mt-0.5 flex min-w-0 items-center gap-2 text-xs text-zinc-500">
                                    <span class="shrink-0 font-mono">{{ $task->number }}</span>
                                    @unless ($projectId)
                                        <span class="truncate">· {{ $task->project->name ?? 'Standalone' }}</span>
                                    @endunless
                                    @if ($task->subtasks_count > 0)
                                        <span class="shrink-0">· {{ $task->completed_subtasks_count }}/{{ $task->subtasks_count }} subtasks</span>
                                    @endif
                                    @if ($task->priority && $task->priority->level >= $this->highPriorityLevel)
                                        <x-ui.master-badge :master="$task->priority" class="shrink-0" />
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $task->picAssignment?->user->name ?? '—' }}</flux:table.cell>
                            @if ($this->showDepartment)
                                <flux:table.cell class="max-xl:hidden">{{ $task->department->name ?? '—' }}</flux:table.cell>
                            @endif
                            <flux:table.cell><x-ui.master-badge :master="$task->status" /></flux:table.cell>
                            <flux:table.cell><x-ui.progress-bar :value="$task->progress_percentage" /></flux:table.cell>
                            <flux:table.cell>
                                @if ($task->current_end)
                                    <x-ui.deadline :date="$task->current_end" :done="$task->status->is_terminal" compact />
                                @else
                                    <span class="text-sm text-zinc-400">No deadline</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div x-on:click.stop class="flex items-center justify-end gap-1">
                                    @if ($this->canQuickUpdate($task))
                                        <flux:button size="sm" variant="ghost" icon="chart-bar" wire:click="openProgress({{ $task->id }})" aria-label="Update progress {{ $task->number }}" />
                                    @endif
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $task->number }}" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('tasks.show', $task)" wire:navigate>View</flux:menu.item>
                                            @if ($this->canQuickUpdate($task))
                                                <flux:menu.item icon="chart-bar" wire:click="openProgress({{ $task->id }})">Update progress</flux:menu.item>
                                                <flux:menu.item icon="pencil-square" :href="route('tasks.edit', $task)" wire:navigate>Edit</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table-card>

        <div class="space-y-3 md:hidden">
            @foreach ($this->tasks as $task)
                <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="card-{{ $task->id }}" class="block rounded-xl border border-zinc-200 bg-white p-4 active:bg-zinc-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-zinc-900">{{ $task->title }}</div>
                            <div class="truncate text-xs text-zinc-500"><span class="font-mono">{{ $task->number }}</span>@unless ($projectId) · {{ $task->project->name ?? 'Standalone' }}@endunless</div>
                        </div>
                        <x-ui.master-badge :master="$task->status" class="shrink-0" />
                    </div>
                    <x-ui.progress-bar :value="$task->progress_percentage" class="mt-3" />
                    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-zinc-500">
                        <span class="truncate">{{ $task->picAssignment?->user->name ?? 'Tanpa PIC' }}{{ $task->department ? ' · '.$task->department->name : '' }}</span>
                        @if ($task->current_end)
                            @php($deadline = \App\Domain\Project\Support\Deadline::for($task->current_end, $task->status->is_terminal))
                            <span @class(['shrink-0', 'text-red-600' => $deadline->tone === 'red', 'text-amber-600' => $deadline->tone === 'amber'])>{{ $deadline->label }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
            <flux:pagination :paginator="$this->tasks" />
        </div>
    @endif

    <flux:modal :name="'task-quick-progress-'.$this->getId()" class="w-full max-w-sm">
        <form wire:submit="saveProgress" class="space-y-6">
            <flux:heading size="lg">Update progress</flux:heading>
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
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
