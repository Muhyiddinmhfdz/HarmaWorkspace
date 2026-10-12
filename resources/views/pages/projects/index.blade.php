<?php

use App\Domain\Project\Actions\ArchiveProject;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\Status;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    use WithDataTable, WithFeedback;

    #[Url(except: 'active')]
    public string $tab = 'active';

    #[Url(except: '')]
    public string $statusId = '';

    #[Url(except: '')]
    public string $priorityId = '';

    #[Url(except: '')]
    public string $clientId = '';

    #[Url(except: '')]
    public string $managerId = '';

    #[Url(except: '')]
    public string $departmentId = '';

    #[Url(except: '')]
    public string $deadline = '';

    public bool $showFilters = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);

        $this->showFilters = $this->activeFilterCount() > 0;
    }

    protected function sortableColumns(): array
    {
        return ['name' => 'name', 'created_at' => 'created_at', 'deadline' => 'current_end', 'progress' => 'progress_percentage'];
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('current_end')->orderBy('name');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'statusId', 'priorityId', 'clientId', 'managerId', 'departmentId', 'deadline'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'statusId', 'priorityId', 'clientId', 'managerId', 'departmentId', 'deadline');
        $this->resetPage();
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([$this->statusId, $this->priorityId, $this->clientId, $this->managerId, $this->departmentId, $this->deadline]));
    }

    /**
     * @return Builder<Project>
     */
    private function visible(): Builder
    {
        return Project::query()->visibleTo(auth()->user());
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function applyTab(Builder $query, string $tab): void
    {
        match (true) {
            $tab === 'active' => $query->open(),
            $tab === 'mine' => $query->whereNull('archived_at')->involving(auth()->user()),
            $tab === 'completed' => $query->whereNull('archived_at')->whereHas('status', fn (Builder $status) => $status->where('counts_as_completed', true)),
            $tab === 'archived' => $query->whereNotNull('archived_at'),
            str_starts_with($tab, 'health-') => $query->open()->where('health_status_id', (int) substr($tab, 7)),
            default => null,
        };
    }

    /**
     * Quick tabs with real counts. Health tabs come from the health master
     * (no hard-coded names) and are hidden when empty.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function tabs(): array
    {
        $count = function (string $tab): int {
            $query = $this->visible();
            $this->applyTab($query, $tab);

            return $query->count();
        };

        $tabs = [
            ['key' => 'active', 'label' => 'Active', 'count' => $count('active')],
            ['key' => 'mine', 'label' => 'My Projects', 'count' => $count('mine')],
        ];

        $healthCounts = $this->visible()->open()->selectRaw('health_status_id, count(*) as aggregate')->groupBy('health_status_id')->pluck('aggregate', 'health_status_id');
        $default = ProjectHealthStatus::query()->where('is_default', true)->value('id');

        ProjectHealthStatus::query()->active()->where('represents_completion', false)->whereKeyNot($default)->orderByDesc('level')->get()
            ->each(function (ProjectHealthStatus $health) use (&$tabs, $healthCounts): void {
                if (($healthCounts[$health->id] ?? 0) > 0 || $this->tab === 'health-'.$health->id) {
                    $tabs[] = ['key' => 'health-'.$health->id, 'label' => $health->name, 'count' => (int) ($healthCounts[$health->id] ?? 0)];
                }
            });

        $tabs[] = ['key' => 'completed', 'label' => 'Completed', 'count' => $count('completed')];
        $tabs[] = ['key' => 'archived', 'label' => 'Archived', 'count' => $count('archived')];
        $tabs[] = ['key' => 'all', 'label' => 'All', 'count' => $count('all')];

        return $tabs;
    }

    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $query = $this->visible()->with([
            'client:id,name',
            'projectManager:id,name,username',
            'leadDepartment.department:id,name',
            'status',
            'priority',
            'health',
        ]);

        $this->applyTab($query, $this->tab);

        $query
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', $this->searchPattern())
                ->orWhere('name', 'like', $this->searchPattern())
                ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $this->searchPattern()))
                ->orWhereHas('projectManager', fn (Builder $user) => $user->where('name', 'like', $this->searchPattern()))))
            ->when($this->statusId !== '', fn (Builder $query) => $query->where('status_id', (int) $this->statusId))
            ->when($this->priorityId !== '', fn (Builder $query) => $query->where('priority_id', (int) $this->priorityId))
            ->when($this->clientId === 'internal', fn (Builder $query) => $query->whereNull('client_id'))
            ->when(ctype_digit($this->clientId), fn (Builder $query) => $query->where('client_id', (int) $this->clientId))
            ->when($this->managerId !== '', fn (Builder $query) => $query->where('project_manager_id', (int) $this->managerId))
            ->when($this->departmentId !== '', fn (Builder $query) => $query->whereHas('departments', fn (Builder $departments) => $departments->where('department_id', (int) $this->departmentId)))
            ->when($this->deadline === 'overdue', fn (Builder $query) => $query->where('current_end', '<', today())->whereNull('completed_at'))
            ->when($this->deadline === '7', fn (Builder $query) => $query->whereBetween('current_end', [today(), today()->addDays(7)]))
            ->when($this->deadline === '30', fn (Builder $query) => $query->whereBetween('current_end', [today(), today()->addDays(30)]));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    /**
     * @return array{statuses: Collection<int, Status>, priorities: Collection<int, Priority>, clients: Collection<int, Client>, managers: Collection<int, User>, departments: Collection<int, Department>}
     */
    #[Computed]
    public function filterOptions(): array
    {
        return [
            'statuses' => Status::query()->forScope(MasterScope::Project)->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Project)->orderByDesc('level')->get(['id', 'name']),
            'clients' => Client::query()->whereIn('id', $this->visible()->whereNotNull('client_id')->select('client_id'))->orderBy('name')->get(['id', 'name']),
            'managers' => User::query()->whereIn('id', $this->visible()->select('project_manager_id'))->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function archive(int $id, ArchiveProject $archiveProject): void
    {
        $project = $this->visible()->findOrFail($id);
        $this->authorize('archive', $project);

        $project->isArchived() ? $archiveProject->restore($project, auth()->user()) : $archiveProject->archive($project, auth()->user());

        $this->notify('success', $project->isArchived() ? 'Project berhasil diarsipkan.' : 'Project berhasil dipulihkan dari arsip.');
        unset($this->tabs, $this->projects);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Projects" description="Kelola dan pantau seluruh project HARMA DIGITAL.">
        <x-slot:actions>
            @can('create', App\Models\Project::class)
                <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>New Project</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Quick tabs --}}
    <div class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <nav class="flex min-w-max gap-1 border-b border-zinc-200" aria-label="Project views">
            @foreach ($this->tabs as $item)
                <button type="button" wire:key="tab-{{ $item['key'] }}" wire:click="$set('tab', '{{ $item['key'] }}')"
                    @class([
                        '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                        'border-accent text-zinc-900' => $tab === $item['key'],
                        'border-transparent text-zinc-500 hover:text-zinc-800' => $tab !== $item['key'],
                    ])
                    @if ($tab === $item['key']) aria-current="page" @endif>
                    {{ $item['label'] }}
                    <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-zinc-900 text-white' => $tab === $item['key'], 'bg-zinc-100 text-zinc-600' => $tab !== $item['key']])>{{ $item['count'] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Search + filters --}}
    <div class="mb-4 space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search number, name, client, PM..." clearable aria-label="Search projects" />
            </div>
            <flux:button icon="adjustments-horizontal" wire:click="$toggle('showFilters')" :variant="$showFilters ? 'filled' : 'outline'">
                More filters
                @if ($this->activeFilterCount() > 0)
                    <flux:badge size="sm" color="indigo" class="ms-1">{{ $this->activeFilterCount() }}</flux:badge>
                @endif
            </flux:button>
            @if ($this->activeFilterCount() > 0 || $search !== '')
                <flux:button variant="ghost" wire:click="resetFilters">Reset</flux:button>
            @endif
        </div>

        @if ($showFilters)
            <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-3">
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
                <flux:select wire:model.live="deadline" label="Deadline">
                    <flux:select.option value="">Any time</flux:select.option>
                    <flux:select.option value="overdue">Overdue</flux:select.option>
                    <flux:select.option value="7">Next 7 days</flux:select.option>
                    <flux:select.option value="30">Next 30 days</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="clientId" label="Client">
                    <flux:select.option value="">All clients</flux:select.option>
                    <flux:select.option value="internal">Internal Project</flux:select.option>
                    @foreach ($this->filterOptions['clients'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fc-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="managerId" label="Project Manager">
                    <flux:select.option value="">All managers</flux:select.option>
                    @foreach ($this->filterOptions['managers'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fm-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="departmentId" label="Department">
                    <flux:select.option value="">All departments</flux:select.option>
                    @foreach ($this->filterOptions['departments'] as $option)
                        <flux:select.option :value="$option->id" wire:key="fd-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    @if ($this->projects->isEmpty())
        <x-ui.table-card>
            @if ($search !== '' || $this->activeFilterCount() > 0)
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada project yang cocok" description="Coba ubah kata kunci atau filter.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="folder" title="Belum ada project di tampilan ini." description="Project yang Anda kelola, ikuti, atau yang melibatkan department Anda akan muncul di sini.">
                    @can('create', App\Models\Project::class)
                        <flux:button variant="primary" icon="plus" size="sm" :href="route('projects.create')" wire:navigate>New Project</flux:button>
                    @endcan
                </x-ui.empty-state>
            @endif
        </x-ui.table-card>
    @else
        {{-- Desktop / tablet table --}}
        <x-ui.table-card class="hidden md:block">
            <flux:table :paginate="$this->projects" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Project</flux:table.column>
                    <flux:table.column class="max-xl:hidden">Client</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Project Manager</flux:table.column>
                    <flux:table.column class="max-xl:hidden">Lead Department</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Health</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'progress'" :direction="$sortDirection" wire:click="sort('progress')" class="w-32">Progress</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'deadline'" :direction="$sortDirection" wire:click="sort('deadline')">Deadline</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->projects as $project)
                        <flux:table.row :key="$project->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('projects.show', $project) }}')">
                            <flux:table.cell class="max-w-72">
                                <div class="font-mono text-xs text-zinc-500">{{ $project->number }}</div>
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="block truncate font-medium text-zinc-900 hover:underline" x-on:click.stop>{{ $project->name }}</a>
                                @if ($project->isArchived())
                                    <flux:badge size="sm" color="zinc" class="mt-1">Archived</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-xl:hidden">{{ $project->client->name ?? 'Internal Project' }}</flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $project->projectManager->name }}</flux:table.cell>
                            <flux:table.cell class="max-xl:hidden">{{ $project->leadDepartment?->department->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell><x-ui.master-badge :master="$project->status" /></flux:table.cell>
                            <flux:table.cell><x-ui.master-badge :master="$project->health" /></flux:table.cell>
                            <flux:table.cell><x-ui.progress-bar :value="$project->progress_percentage" /></flux:table.cell>
                            <flux:table.cell><x-ui.deadline :date="$project->current_end" :done="$project->completed_at !== null" compact /></flux:table.cell>
                            <flux:table.cell align="end">
                                <div x-on:click.stop>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $project->name }}" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('projects.show', $project)" wire:navigate>View</flux:menu.item>
                                            @can('update', $project)
                                                @unless ($project->isArchived())
                                                    <flux:menu.item icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>Edit</flux:menu.item>
                                                @endunless
                                            @endcan
                                            @can('archive', $project)
                                                @if ($project->isArchived())
                                                    <flux:menu.item icon="arrow-uturn-left"
                                                        x-on:click="hwConfirm({ title: 'Pulihkan project dari arsip?', text: 'Project akan kembali tampil di daftar dan dapat diubah lagi.', confirmText: 'Pulihkan' }).then(ok => ok && $wire.archive({{ $project->id }}))">Restore</flux:menu.item>
                                                @elseif ($project->status->is_terminal)
                                                    <flux:menu.item icon="archive-box" variant="danger"
                                                        x-on:click="hwConfirm({ title: 'Arsipkan Project?', text: 'Project tidak akan tampil di daftar project aktif, tetapi seluruh data dan riwayat tetap tersimpan.', confirmText: 'Arsipkan', cancelText: 'Batal', danger: true }).then(ok => ok && $wire.archive({{ $project->id }}))">Archive</flux:menu.item>
                                                @endif
                                            @endcan
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table-card>

        {{-- Mobile cards --}}
        <div class="space-y-3 md:hidden">
            @foreach ($this->projects as $project)
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="card-{{ $project->id }}" class="block rounded-xl border border-zinc-200 bg-white p-4 active:bg-zinc-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-mono text-xs text-zinc-500">{{ $project->number }}</div>
                            <div class="truncate font-medium text-zinc-900">{{ $project->name }}</div>
                        </div>
                        <x-ui.master-badge :master="$project->health" />
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.master-badge :master="$project->status" />
                        <span class="text-xs text-zinc-500">PM: {{ $project->projectManager->name }}</span>
                    </div>
                    <x-ui.progress-bar :value="$project->progress_percentage" class="mt-3" />
                    <x-ui.deadline :date="$project->current_end" :done="$project->completed_at !== null" class="mt-2" />
                </a>
            @endforeach
            <flux:pagination :paginator="$this->projects" />
        </div>
    @endif
</div>
