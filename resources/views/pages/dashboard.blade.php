<?php

use App\Domain\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Project;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectMilestone;
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

        if ($user->can('user.view')) {
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
            ->with('causer:id,name,username')
            ->latest('id')
            ->limit(6)
            ->get();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->greeting().', '.auth()->user()->name" description="Berikut ringkasan workspace Anda hari ini." />

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
                <flux:text class="mt-1">Tasks (Phase 3) dan Tickets (Phase 4) akan muncul di dashboard ini.</flux:text>
            </div>
        </div>
    </div>
</div>
