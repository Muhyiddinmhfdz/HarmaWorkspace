<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Actions\ChangeUserStatus;
use App\Domain\Organization\Actions\SendPasswordResetLink;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Activity;
use App\Models\DepartmentMembership;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $userId;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);

        $this->userId = $user->id;
    }

    #[Computed]
    public function member(): User
    {
        return User::query()->with('roles:id,name')->findOrFail($this->userId);
    }

    /**
     * Alias used by the shared status modal partial.
     */
    #[Computed]
    public function statusTarget(): User
    {
        return $this->member;
    }

    /**
     * @return Collection<int, DepartmentMembership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return $this->member->memberships()
            ->with(['department:id,code,name,is_active', 'position:id,name'])
            ->orderByRaw('ended_at IS NULL DESC')
            ->orderByDesc('is_primary')
            ->orderByDesc('joined_at')
            ->get();
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activity(): Collection
    {
        if (! auth()->user()->can('audit.view')) {
            return collect();
        }

        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->where('event', '!=', 'workflow.transition_executed')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('subject_type', 'user')->where('subject_id', $this->userId))
                ->orWhere(fn ($query) => $query->where('causer_type', 'user')->where('causer_id', $this->userId)))
            ->with('causer:id,name')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    public function confirmStatus(): void
    {
        $this->authorize($this->member->is_active ? 'deactivate' : 'activate', $this->member);

        Flux::modal('user-status')->show();
    }

    public function changeStatus(ChangeUserStatus $changeStatus): void
    {
        $activate = ! $this->member->is_active;
        $this->authorize($activate ? 'activate' : 'deactivate', $this->member);

        $changeStatus->handle($this->member, $activate, auth()->user());

        unset($this->member, $this->statusTarget, $this->activity);
        Flux::modal('user-status')->close();
        $this->notify('success', $activate ? 'User berhasil diaktifkan.' : 'User berhasil dinonaktifkan.');
    }

    public function sendResetLink(SendPasswordResetLink $sendResetLink): void
    {
        $this->authorize('update', $this->member);

        $sendResetLink->handle($this->member);

        unset($this->activity);
        Flux::modal('user-reset-link')->close();
        $this->notify('success', 'Link reset password telah dikirim ke '.$this->member->email.'.');
    }

    public function render()
    {
        return $this->view()->title($this->member->name);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->member->name" :description="'@'.$this->member->username">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                @can('viewAny', App\Models\User::class)
                    <flux:breadcrumbs.item :href="route('admin.users.index')" wire:navigate>Members</flux:breadcrumbs.item>
                @endcan
                <flux:breadcrumbs.item>{{ $this->member->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('update', $this->member)
                <flux:modal.trigger name="user-reset-link">
                    <flux:button icon="envelope">Send reset link</flux:button>
                </flux:modal.trigger>
                <flux:button icon="pencil-square" :href="route('admin.users.edit', $this->member)" wire:navigate>Edit</flux:button>
            @endcan
            @if ($this->member->is_active)
                @can('deactivate', $this->member)
                    <flux:button variant="danger" icon="no-symbol" wire:click="confirmStatus">Deactivate</flux:button>
                @endcan
            @else
                @can('activate', $this->member)
                    <flux:button variant="primary" icon="check-circle" wire:click="confirmStatus">Activate</flux:button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.table-card>
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                    <flux:heading size="lg">Department memberships</flux:heading>
                </div>

                @if ($this->memberships->isEmpty())
                    <x-ui.empty-state icon="building-office-2" title="Belum ada department" description="Tambahkan department melalui tombol Edit." />
                @else
                    <flux:table class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5">
                        <flux:table.columns>
                            <flux:table.column>Department</flux:table.column>
                            <flux:table.column>Position</flux:table.column>
                            <flux:table.column>Role</flux:table.column>
                            <flux:table.column class="max-md:hidden">Period</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->memberships as $membership)
                                <flux:table.row :key="$membership->id" @class(['opacity-60' => $membership->ended_at])>
                                    <flux:table.cell variant="strong">
                                        {{ $membership->department->name }}
                                        @unless ($membership->department->is_active)
                                            <flux:badge size="sm" color="zinc" class="ms-1">Inactive</flux:badge>
                                        @endunless
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $membership->position?->name ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($membership->ended_at)
                                            <flux:badge size="sm" color="zinc">Ended</flux:badge>
                                        @else
                                            @if ($membership->is_primary)
                                                <flux:badge size="sm" color="blue">Primary</flux:badge>
                                            @endif
                                            @if ($membership->is_leader)
                                                <flux:badge size="sm" color="amber">Leader</flux:badge>
                                            @endif
                                            @if (! $membership->is_primary && ! $membership->is_leader)
                                                <flux:text size="sm">Member</flux:text>
                                            @endif
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="max-md:hidden text-sm">
                                        {{ $membership->joined_at?->format('d M Y') ?? '—' }}
                                        @if ($membership->ended_at)
                                            – {{ $membership->ended_at->format('d M Y') }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </x-ui.table-card>

            @can('audit.view')
                <x-ui.table-card>
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <flux:heading size="lg">Recent activity</flux:heading>
                    </div>
                    @forelse ($this->activity as $entry)
                        <div wire:key="activity-{{ $entry->id }}" class="flex items-start justify-between gap-4 border-b border-zinc-100 px-5 py-3 last:border-b-0 dark:border-zinc-800">
                            <div class="text-sm text-zinc-800 dark:text-zinc-100">
                                <span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span>
                                {{ \Illuminate\Support\Str::lcfirst($entry->description) }}
                            </div>
                            <flux:text size="sm" class="shrink-0">{{ $entry->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <x-ui.empty-state icon="clock" title="Belum ada aktivitas" class="py-8" />
                    @endforelse
                </x-ui.table-card>
            @endcan
        </div>

        <flux:card class="h-fit">
            <div class="mb-4 flex items-center gap-3">
                <flux:avatar size="lg" :name="$this->member->name" :initials="$this->member->initials()" />
                <div class="min-w-0">
                    <flux:heading class="truncate">{{ $this->member->name }}</flux:heading>
                    <x-ui.active-badge :active="$this->member->is_active" />
                </div>
            </div>
            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <x-ui.detail-item label="Username">{{ $this->member->username }}</x-ui.detail-item>
                <x-ui.detail-item label="Email">{{ $this->member->email }}</x-ui.detail-item>
                <x-ui.detail-item label="Phone">{{ $this->member->phone }}</x-ui.detail-item>
                <x-ui.detail-item label="System role">{{ $this->member->roles->pluck('name')->join(', ') }}</x-ui.detail-item>
                <x-ui.detail-item label="Two-factor">{{ $this->member->two_factor_confirmed_at ? 'Aktif' : 'Tidak aktif' }}</x-ui.detail-item>
                <x-ui.detail-item label="Last login">{{ $this->member->last_login_at?->format('d M Y H:i') }}</x-ui.detail-item>
                <x-ui.detail-item label="Created">{{ $this->member->created_at?->format('d M Y') }}</x-ui.detail-item>
                @if ($this->member->deactivated_at)
                    <x-ui.detail-item label="Deactivated">{{ $this->member->deactivated_at->format('d M Y H:i') }}</x-ui.detail-item>
                @endif
            </dl>
        </flux:card>
    </div>

    @include('partials.user-status-modal')

    <flux:modal name="user-reset-link" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Kirim link reset password?</flux:heading>
                <flux:text class="mt-2">
                    @if ($this->member->email)
                        {{ $this->member->name }} akan menerima email di {{ $this->member->email }} berisi link untuk membuat password baru. Password lama tetap berlaku sampai diganti.
                    @else
                        User ini belum memiliki email, sehingga link tidak dapat dikirim. Isi email melalui Edit, atau atur password baru langsung di form Edit.
                    @endif
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="sendResetLink" wire:loading.attr="disabled">Send link</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
