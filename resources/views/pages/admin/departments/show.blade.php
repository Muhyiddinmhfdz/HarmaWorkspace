<?php

use App\Models\Department;
use App\Models\DepartmentMembership;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public int $departmentId;

    public function mount(Department $department): void
    {
        $this->authorize('view', $department);

        $this->departmentId = $department->id;
    }

    #[Computed]
    public function department(): Department
    {
        return Department::query()
            ->with(['parent:id,name', 'children' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->findOrFail($this->departmentId);
    }

    /**
     * Current members, leaders first.
     *
     * @return Collection<int, DepartmentMembership>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->department->activeMemberships()
            ->with(['user:id,name,email,is_active', 'position:id,name,level'])
            ->orderByDesc('is_leader')
            ->get()
            ->sortBy(fn (DepartmentMembership $membership): string => ($membership->is_leader ? '0' : '1').$membership->user->name)
            ->values();
    }

    public function render()
    {
        return $this->view()->title($this->department->name);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->department->name" :description="$this->department->description">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.departments.index')" wire:navigate>Departments</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->department->code }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="h-fit">
            <flux:heading size="lg" class="mb-2">Details</flux:heading>
            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <x-ui.detail-item label="Code"><span class="font-mono">{{ $this->department->code }}</span></x-ui.detail-item>
                <x-ui.detail-item label="Status"><x-ui.active-badge :active="$this->department->is_active" /></x-ui.detail-item>
                <x-ui.detail-item label="Parent">{{ $this->department->parent?->name }}</x-ui.detail-item>
                <x-ui.detail-item label="Sub-departments">
                    @foreach ($this->department->children as $child)
                        <a href="{{ route('admin.departments.show', $child) }}" wire:navigate class="block hover:underline">{{ $child->name }}</a>
                    @endforeach
                </x-ui.detail-item>
                <x-ui.detail-item label="Sort order">{{ $this->department->sort_order }}</x-ui.detail-item>
            </dl>
        </flux:card>

        <x-ui.table-card class="lg:col-span-2">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                <flux:heading size="lg">Members <span class="text-zinc-400">({{ $this->members->count() }})</span></flux:heading>
            </div>

            @if ($this->members->isEmpty())
                <x-ui.empty-state icon="users" title="Belum ada anggota" description="Tambahkan anggota melalui halaman Members dengan memilih department ini.">
                    @can('user.view')
                        <flux:button size="sm" :href="route('admin.users.index')" wire:navigate>Buka Members</flux:button>
                    @endcan
                </x-ui.empty-state>
            @else
                <flux:table class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5">
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Position</flux:table.column>
                        <flux:table.column>Role in department</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->members as $membership)
                            <flux:table.row :key="$membership->id">
                                <flux:table.cell>
                                    <div class="flex items-center gap-3">
                                        <flux:avatar size="sm" :name="$membership->user->name" />
                                        <div class="min-w-0">
                                            @can('view', $membership->user)
                                                <a href="{{ route('admin.users.show', $membership->user) }}" wire:navigate class="font-medium text-zinc-800 hover:underline dark:text-white">{{ $membership->user->name }}</a>
                                            @else
                                                <span class="font-medium text-zinc-800 dark:text-white">{{ $membership->user->name }}</span>
                                            @endcan
                                            @unless ($membership->user->is_active)
                                                <flux:badge size="sm" color="zinc" class="ms-1">Inactive</flux:badge>
                                            @endunless
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $membership->position?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($membership->is_leader)
                                        <flux:badge size="sm" color="amber">Leader</flux:badge>
                                    @else
                                        <flux:text size="sm">Member</flux:text>
                                    @endif
                                    @if ($membership->is_primary)
                                        <flux:badge size="sm" color="blue" class="ms-1">Primary</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-ui.table-card>
    </div>
</div>
