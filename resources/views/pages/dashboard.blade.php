<?php

use App\Domain\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\User;
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

    /**
     * Real counts only, and only for modules the user may view.
     *
     * @return list<array{label: string, value: int, icon: string, url: string}>
     */
    #[Computed]
    public function summary(): array
    {
        $user = Auth::user();

        return array_values(array_filter([
            $user->can('user.view') ? [
                'label' => 'Active Members',
                'value' => User::query()->active()->count(),
                'icon' => 'users',
                'url' => route('admin.users.index', ['status' => 'active']),
            ] : null,
            $user->can('department.view') ? [
                'label' => 'Departments',
                'value' => Department::query()->active()->count(),
                'icon' => 'building-office-2',
                'url' => route('admin.departments.index', ['status' => 'active']),
            ] : null,
            $user->can('client.view') ? [
                'label' => 'Active Clients',
                'value' => Client::query()->active()->count(),
                'icon' => 'briefcase',
                'url' => route('clients.index', ['status' => 'active']),
            ] : null,
        ]));
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
     * Latest audit events, for users allowed to read the audit log.
     *
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
            ->with('causer:id,name')
            ->latest('id')
            ->limit(6)
            ->get();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->greeting().', '.auth()->user()->name" description="Berikut ringkasan workspace Anda hari ini." />

    @if ($this->summary !== [])
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->summary as $card)
                <a href="{{ $card['url'] }}" wire:navigate wire:key="summary-{{ $loop->index }}"
                   class="group rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                    <div class="flex items-center justify-between">
                        <flux:text>{{ $card['label'] }}</flux:text>
                        <flux:icon :icon="$card['icon']" class="size-5 text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-300" />
                    </div>
                    <div class="mt-3 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ number_format($card['value']) }}</div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <flux:card class="p-0!">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                    <flux:heading size="lg">My Work</flux:heading>
                    <flux:text class="mt-1">Workspace modules will appear here as HarmaWorkspace grows.</flux:text>
                </div>
                <div class="grid divide-y divide-zinc-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-zinc-700">
                    @foreach ([
                        ['Projects', 'folder', 'Project multi-division, milestone, dan anggota tim.', 'Phase 2'],
                        ['Tasks', 'check-circle', 'Task, subtask, PIC, dan deadline Anda.', 'Phase 3'],
                        ['Tickets', 'lifebuoy', 'Permintaan antar divisi dan Department Inbox.', 'Phase 4'],
                    ] as [$module, $icon, $text, $phase])
                        <div class="p-5">
                            <div class="flex items-center gap-2">
                                <flux:icon :icon="$icon" class="size-5 text-zinc-400" />
                                <flux:heading>{{ $module }}</flux:heading>
                            </div>
                            <flux:text class="mt-2">{{ $text }}</flux:text>
                            <flux:badge size="sm" color="zinc" class="mt-3">Segera hadir · {{ $phase }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            @can('audit.view')
                <flux:card class="p-0!">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <flux:heading size="lg">Recent Activity</flux:heading>
                        <flux:link :href="route('admin.audit.index')" wire:navigate class="text-sm">Lihat audit log</flux:link>
                    </div>

                    @forelse ($this->recentActivity as $activity)
                        <div wire:key="activity-{{ $activity->id }}" class="flex items-start gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 dark:border-zinc-800">
                            @if ($activity->causer)
                                <flux:avatar size="xs" :name="$activity->causer->name" :initials="$activity->causer->initials()" />
                            @else
                                <flux:avatar size="xs" icon="cog-6-tooth" />
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-zinc-800 dark:text-zinc-100">
                                    <span class="font-medium">{{ $activity->causer?->name ?? 'System' }}</span>
                                    {{ \Illuminate\Support\Str::lcfirst($activity->description) }}
                                </div>
                                <flux:text size="sm">{{ $activity->created_at->diffForHumans() }}</flux:text>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="clock" title="Belum ada aktivitas" description="Aktivitas penting seperti perubahan user dan master data akan tercatat di sini." />
                    @endforelse
                </flux:card>
            @endcan
        </div>

        <flux:card class="h-fit p-0!">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                <flux:heading size="lg">My Organization</flux:heading>
            </div>

            @forelse ($this->memberships as $membership)
                <div wire:key="membership-{{ $membership->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 last:border-b-0 dark:border-zinc-800">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $membership->department->name }}</div>
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
    </div>
</div>
