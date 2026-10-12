<?php

use App\Domain\Organization\Actions\ChangeUserStatus;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Department;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Members')] class extends Component {
    use WithDataTable, WithFeedback;

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $role = '';

    #[Locked]
    public ?int $statusTargetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    protected function sortableColumns(): array
    {
        return ['name' => 'name', 'username' => 'username', 'last_login_at' => 'last_login_at'];
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('name');
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'department', 'role');
        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $query = User::query()
            ->visibleTo(auth()->user())
            ->with(['primaryMembership.department:id,name', 'primaryMembership.position:id,name', 'roles:id,name'])
            ->status($this->status)
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('name', 'like', $this->searchPattern())
                    ->orWhere('username', 'like', $this->searchPattern())
                    ->orWhere('email', 'like', $this->searchPattern())
            ))
            ->when($this->department !== '', fn (Builder $query) => $query->whereHas(
                'activeMemberships',
                fn (Builder $query) => $query->where('department_id', (int) $this->department)
            ))
            ->when($this->role !== '', fn (Builder $query) => $query->whereHas(
                'roles',
                fn (Builder $query) => $query->where('name', $this->role)
            ));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departmentOptions(): Collection
    {
        return Department::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function roleOptions(): Collection
    {
        return Role::query()->orderBy('name')->pluck('name');
    }

    #[Computed]
    public function statusTarget(): ?User
    {
        return $this->statusTargetId ? User::find($this->statusTargetId) : null;
    }

    public function confirmStatus(int $id): void
    {
        $user = User::findOrFail($id);
        $this->authorize($user->is_active ? 'deactivate' : 'activate', $user);

        $this->statusTargetId = $user->id;
        Flux::modal('user-status')->show();
    }

    public function changeStatus(ChangeUserStatus $changeStatus): void
    {
        $user = User::findOrFail($this->statusTargetId);
        $activate = ! $user->is_active;
        $this->authorize($activate ? 'activate' : 'deactivate', $user);

        $changeStatus->handle($user, $activate, auth()->user());

        Flux::modal('user-status')->close();
        $this->notify('success', $activate ? 'User berhasil diaktifkan.' : 'User berhasil dinonaktifkan.');
        $this->statusTargetId = null;
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Members" description="Kelola akun internal, keanggotaan department, dan role sistem.">
        <x-slot:actions>
            @can('create', App\Models\User::class)
                <flux:button variant="primary" icon="plus" :href="route('admin.users.create')" wire:navigate>Add Member</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search name, username, email..." clearable aria-label="Search members" />
        <flux:select wire:model.live="department" aria-label="Filter department">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach ($this->departmentOptions as $option)
                <flux:select.option :value="$option->id" wire:key="department-option-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="role" aria-label="Filter role">
            <flux:select.option value="">All roles</flux:select.option>
            @foreach ($this->roleOptions as $roleName)
                <flux:select.option :value="$roleName" wire:key="role-option-{{ $loop->index }}">{{ $roleName }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" aria-label="Filter status">
            <flux:select.option value="">All status</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
        </flux:select>
    </div>

    <x-ui.table-card>
        @if ($this->users->isEmpty())
            @if ($search !== '' || $status !== '' || $department !== '' || $role !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada member yang cocok" description="Coba ubah kata kunci atau filter.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="users" title="Belum ada member." description="Tambahkan akun pertama untuk anggota tim HARMA DIGITAL.">
                    @can('create', App\Models\User::class)
                        <flux:button variant="primary" icon="plus" size="sm" :href="route('admin.users.create')" wire:navigate>Add Member</flux:button>
                    @endcan
                </x-ui.empty-state>
            @endif
        @else
            <flux:table :paginate="$this->users" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
                    <flux:table.column class="max-md:hidden">Primary Department</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Position</flux:table.column>
                    <flux:table.column class="max-sm:hidden">System Role</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'last_login_at'" :direction="$sortDirection" wire:click="sort('last_login_at')" class="max-lg:hidden">Last Login</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->users as $member)
                        <flux:table.row :key="$member->id">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :name="$member->name" :initials="$member->initials()" />
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.users.show', $member) }}" wire:navigate class="block truncate font-medium text-zinc-800 hover:underline dark:text-white">{{ $member->name }}</a>
                                        <div class="truncate text-xs text-zinc-500">{{ '@'.$member->username }}</div>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $member->primaryMembership?->department->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $member->primaryMembership?->position?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">
                                @forelse ($member->roles as $memberRole)
                                    <flux:badge size="sm" color="zinc" inset="top bottom">{{ $memberRole->name }}</flux:badge>
                                @empty
                                    <span class="text-zinc-400">—</span>
                                @endforelse
                            </flux:table.cell>
                            <flux:table.cell><x-ui.active-badge :active="$member->is_active" /></flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">
                                @if ($member->last_login_at)
                                    <span title="{{ $member->last_login_at->format('d M Y H:i') }}">{{ $member->last_login_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-zinc-400">Belum pernah</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $member->name }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" :href="route('admin.users.show', $member)" wire:navigate>View</flux:menu.item>
                                        @can('update', $member)
                                            <flux:menu.item icon="pencil-square" :href="route('admin.users.edit', $member)" wire:navigate>Edit</flux:menu.item>
                                        @endcan
                                        @if ($member->is_active)
                                            @can('deactivate', $member)
                                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmStatus({{ $member->id }})">Deactivate</flux:menu.item>
                                            @endcan
                                        @else
                                            @can('activate', $member)
                                                <flux:menu.item icon="check-circle" wire:click="confirmStatus({{ $member->id }})">Activate</flux:menu.item>
                                            @endcan
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-ui.table-card>

    @include('partials.user-status-modal')
</div>
