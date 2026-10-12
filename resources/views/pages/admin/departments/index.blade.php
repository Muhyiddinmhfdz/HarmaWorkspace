<?php

use App\Domain\MasterData\Actions\ChangeMasterStatus;
use App\Domain\Organization\Actions\CreateDepartment;
use App\Domain\Organization\Actions\UpdateDepartment;
use App\Domain\Organization\Rules\DepartmentRules;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Department;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Departments')] class extends Component {
    use WithDataTable, WithFeedback;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $parent_id = '';

    public string $sort_order = '0';

    #[Locked]
    public ?int $statusTargetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);

        if (request()->boolean('create') && auth()->user()->can('create', Department::class)) {
            $this->create();
        }
    }

    protected function sortableColumns(): array
    {
        return ['code' => 'code', 'name' => 'name', 'sort_order' => 'sort_order'];
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    #[Computed]
    public function departments(): LengthAwarePaginator
    {
        $query = Department::query()
            ->with('parent:id,name')
            ->withCount('activeMemberships')
            ->status($this->status)
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('name', 'like', $this->searchPattern())->orWhere('code', 'like', $this->searchPattern())
            ));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    /**
     * Active departments that may become the parent of the edited one.
     *
     * @return Collection<int, Department>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $excluded = $this->editingId ? Department::findOrFail($this->editingId)->selfAndDescendantIds() : [];

        return Department::query()
            ->active()
            ->whereNotIn('id', $excluded)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function codeLocked(): bool
    {
        return $this->editingId !== null && Department::findOrFail($this->editingId)->isCodeLocked();
    }

    #[Computed]
    public function statusTarget(): ?Department
    {
        return $this->statusTargetId ? Department::find($this->statusTargetId) : null;
    }

    public function create(): void
    {
        $this->authorize('create', Department::class);

        $this->resetForm();
        $this->sort_order = (string) (((int) Department::max('sort_order')) + 10);
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->authorize('update', $department);

        $this->resetForm();
        $this->editingId = $department->id;
        $this->code = $department->code;
        $this->name = $department->name;
        $this->description = (string) $department->description;
        $this->parent_id = (string) $department->parent_id;
        $this->sort_order = (string) $department->sort_order;
        $this->showForm = true;
    }

    public function save(CreateDepartment $createDepartment, UpdateDepartment $updateDepartment): void
    {
        $department = $this->editingId ? Department::findOrFail($this->editingId) : null;
        $department ? $this->authorize('update', $department) : $this->authorize('create', Department::class);

        $this->code = strtoupper(trim($this->code));
        $validated = $this->validate(DepartmentRules::rules($department));
        $validated['parent_id'] = filled($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        $validated['sort_order'] = (int) $validated['sort_order'];

        if ($department) {
            $updateDepartment->handle($department, $validated);
            $this->notify('success', 'Department berhasil diperbarui.');
        } else {
            $createDepartment->handle($validated);
            $this->notify('success', 'Department berhasil ditambahkan.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmStatus(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->authorize($department->is_active ? 'deactivate' : 'activate', $department);

        $this->statusTargetId = $department->id;
        Flux::modal('department-status')->show();
    }

    public function changeStatus(ChangeMasterStatus $changeStatus): void
    {
        $department = Department::findOrFail($this->statusTargetId);
        $activate = ! $department->is_active;
        $this->authorize($activate ? 'activate' : 'deactivate', $department);

        $changeStatus->handle($department, $activate);

        Flux::modal('department-status')->close();
        $this->notify('success', $activate ? 'Department berhasil diaktifkan.' : 'Department berhasil dinonaktifkan.');
        $this->statusTargetId = null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'description', 'parent_id', 'sort_order');
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Departments" description="Kelola struktur divisi HARMA DIGITAL. Department yang sudah digunakan dinonaktifkan, bukan dihapus.">
        <x-slot:actions>
            @can('create', App\Models\Department::class)
                <flux:button variant="primary" icon="plus" wire:click="create">Add Department</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="w-full sm:max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search code or name..." clearable aria-label="Search departments" />
        </div>
        <div class="w-full sm:w-44">
            <flux:select wire:model.live="status" aria-label="Filter status">
                <flux:select.option value="">All status</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
        </div>
    </div>

    <x-ui.table-card>
        @if ($this->departments->isEmpty())
            @if ($search !== '' || $status !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada department yang cocok" description="Coba ubah kata kunci atau filter status.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="building-office-2" title="Belum ada Department." description="Tambahkan Department pertama untuk mulai menyusun struktur organisasi HARMA DIGITAL.">
                    @can('create', App\Models\Department::class)
                        <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add Department</flux:button>
                    @endcan
                </x-ui.empty-state>
            @endif
        @else
            <flux:table :paginate="$this->departments" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Department</flux:table.column>
                    <flux:table.column class="max-md:hidden">Parent</flux:table.column>
                    <flux:table.column>Members</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'sort_order'" :direction="$sortDirection" wire:click="sort('sort_order')" class="max-md:hidden">Order</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->departments as $department)
                        <flux:table.row :key="$department->id">
                            <flux:table.cell class="font-mono text-xs">{{ $department->code }}</flux:table.cell>
                            <flux:table.cell variant="strong">
                                <a href="{{ route('admin.departments.show', $department) }}" wire:navigate class="hover:underline">{{ $department->name }}</a>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $department->parent?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $department->active_memberships_count }}</flux:table.cell>
                            <flux:table.cell><x-ui.active-badge :active="$department->is_active" /></flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $department->sort_order }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $department->name }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" :href="route('admin.departments.show', $department)" wire:navigate>View</flux:menu.item>
                                        @can('update', $department)
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $department->id }})">Edit</flux:menu.item>
                                        @endcan
                                        @if ($department->is_active)
                                            @can('deactivate', $department)
                                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmStatus({{ $department->id }})">Deactivate</flux:menu.item>
                                            @endcan
                                        @else
                                            @can('activate', $department)
                                                <flux:menu.item icon="check-circle" wire:click="confirmStatus({{ $department->id }})">Activate</flux:menu.item>
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

    @canany(['department.create', 'department.update'])
    <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Department' : 'Add Department' }}</flux:heading>
                <flux:text class="mt-1">Nama dapat diubah kapan saja; code dipakai sebagai identitas tetap.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="code" label="Code" badge="Required" class="uppercase" maxlength="30" :disabled="$this->codeLocked"
                    :description="$this->codeLocked ? 'Terkunci karena sudah digunakan.' : null" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="name" label="Name" badge="Required" maxlength="100" />
                </div>
            </div>

            <flux:select wire:model="parent_id" label="Parent department" placeholder="Tanpa parent">
                <flux:select.option value="">Tanpa parent</flux:select.option>
                @foreach ($this->parentOptions as $option)
                    <flux:select.option :value="$option->id" wire:key="parent-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="description" label="Description" rows="3" />

            <flux:input wire:model="sort_order" type="number" min="0" label="Sort order" description="Urutan tampil; angka kecil tampil lebih dulu." class="max-w-40" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
    @endcanany

    <flux:modal name="department-status" class="w-full max-w-md">
        @if ($this->statusTarget)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->statusTarget->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $this->statusTarget->name }}?</flux:heading>
                    <flux:text class="mt-2">
                        @if ($this->statusTarget->is_active)
                            Department tidak dapat dipilih untuk keanggotaan baru. Anggota dan data yang sudah ada tetap tersimpan.
                        @else
                            Department dapat kembali dipilih untuk keanggotaan baru.
                        @endif
                    </flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button :variant="$this->statusTarget->is_active ? 'danger' : 'primary'" wire:click="changeStatus" wire:loading.attr="disabled">
                        {{ $this->statusTarget->is_active ? 'Deactivate' : 'Activate' }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
