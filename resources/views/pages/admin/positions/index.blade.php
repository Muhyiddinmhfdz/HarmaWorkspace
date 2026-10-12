<?php

use App\Domain\MasterData\Actions\ChangeMasterStatus;
use App\Domain\Organization\Actions\CreatePosition;
use App\Domain\Organization\Actions\UpdatePosition;
use App\Domain\Organization\Rules\PositionRules;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Position;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Positions')] class extends Component {
    use WithDataTable, WithFeedback;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $level = '';

    public string $sort_order = '0';

    #[Locked]
    public ?int $statusTargetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Position::class);
    }

    protected function sortableColumns(): array
    {
        return ['code' => 'code', 'name' => 'name', 'level' => 'level', 'sort_order' => 'sort_order'];
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    #[Computed]
    public function positions(): LengthAwarePaginator
    {
        $query = Position::query()
            ->withCount(['memberships' => fn (Builder $query) => $query->whereNull('ended_at')])
            ->status($this->status)
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('name', 'like', $this->searchPattern())->orWhere('code', 'like', $this->searchPattern())
            ));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    #[Computed]
    public function codeLocked(): bool
    {
        return $this->editingId !== null && Position::findOrFail($this->editingId)->isCodeLocked();
    }

    #[Computed]
    public function statusTarget(): ?Position
    {
        return $this->statusTargetId ? Position::find($this->statusTargetId) : null;
    }

    public function create(): void
    {
        $this->authorize('create', Position::class);

        $this->resetForm();
        $this->sort_order = (string) (((int) Position::max('sort_order')) + 10);
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $position = Position::findOrFail($id);
        $this->authorize('update', $position);

        $this->resetForm();
        $this->editingId = $position->id;
        $this->code = $position->code;
        $this->name = $position->name;
        $this->description = (string) $position->description;
        $this->level = (string) $position->level;
        $this->sort_order = (string) $position->sort_order;
        $this->showForm = true;
    }

    public function save(CreatePosition $createPosition, UpdatePosition $updatePosition): void
    {
        $position = $this->editingId ? Position::findOrFail($this->editingId) : null;
        $position ? $this->authorize('update', $position) : $this->authorize('create', Position::class);

        $this->code = strtoupper(trim($this->code));
        $validated = $this->validate(PositionRules::rules($position));
        $validated['level'] = filled($validated['level']) ? (int) $validated['level'] : null;
        $validated['sort_order'] = (int) $validated['sort_order'];

        if ($position) {
            $updatePosition->handle($position, $validated);
            $this->notify('success', 'Position berhasil diperbarui.');
        } else {
            $createPosition->handle($validated);
            $this->notify('success', 'Position berhasil ditambahkan.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmStatus(int $id): void
    {
        $position = Position::findOrFail($id);
        $this->authorize($position->is_active ? 'deactivate' : 'activate', $position);

        $this->statusTargetId = $position->id;
        Flux::modal('position-status')->show();
    }

    public function changeStatus(ChangeMasterStatus $changeStatus): void
    {
        $position = Position::findOrFail($this->statusTargetId);
        $activate = ! $position->is_active;
        $this->authorize($activate ? 'activate' : 'deactivate', $position);

        $changeStatus->handle($position, $activate);

        Flux::modal('position-status')->close();
        $this->notify('success', $activate ? 'Position berhasil diaktifkan.' : 'Position berhasil dinonaktifkan.');
        $this->statusTargetId = null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'description', 'level', 'sort_order');
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Positions" description="Master jabatan yang dapat dipilih pada keanggotaan department.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('master-data.index')" wire:navigate>Master Data</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Positions</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('create', App\Models\Position::class)
                <flux:button variant="primary" icon="plus" wire:click="create">Add Position</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="w-full sm:max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search code or name..." clearable aria-label="Search positions" />
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
        @if ($this->positions->isEmpty())
            @if ($search !== '' || $status !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada position yang cocok" description="Coba ubah kata kunci atau filter status.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="identification" title="Belum ada Position." description="Tambahkan jabatan seperti Staff atau Manager agar dapat dipilih saat mengatur keanggotaan department.">
                    @can('create', App\Models\Position::class)
                        <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add Position</flux:button>
                    @endcan
                </x-ui.empty-state>
            @endif
        @else
            <flux:table :paginate="$this->positions" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Position</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'level'" :direction="$sortDirection" wire:click="sort('level')" class="max-md:hidden">Level</flux:table.column>
                    <flux:table.column>Members</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'sort_order'" :direction="$sortDirection" wire:click="sort('sort_order')" class="max-md:hidden">Order</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->positions as $position)
                        <flux:table.row :key="$position->id">
                            <flux:table.cell class="font-mono text-xs">{{ $position->code }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $position->name }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $position->level ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $position->memberships_count }}</flux:table.cell>
                            <flux:table.cell><x-ui.active-badge :active="$position->is_active" /></flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $position->sort_order }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @canany(['update', 'activate', 'deactivate'], $position)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $position->name }}" />
                                        <flux:menu>
                                            @can('update', $position)
                                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $position->id }})">Edit</flux:menu.item>
                                            @endcan
                                            @if ($position->is_active)
                                                @can('deactivate', $position)
                                                    <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmStatus({{ $position->id }})">Deactivate</flux:menu.item>
                                                @endcan
                                            @else
                                                @can('activate', $position)
                                                    <flux:menu.item icon="check-circle" wire:click="confirmStatus({{ $position->id }})">Activate</flux:menu.item>
                                                @endcan
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcanany
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-ui.table-card>

    @canany(['position.create', 'position.update'])
    <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Position' : 'Add Position' }}</flux:heading>
                <flux:text class="mt-1">Level dipakai untuk mengurutkan senioritas, bukan nama jabatan.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="code" label="Code" badge="Required" class="uppercase" maxlength="30" :disabled="$this->codeLocked"
                    :description="$this->codeLocked ? 'Terkunci karena sudah digunakan.' : null" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="name" label="Name" badge="Required" maxlength="100" />
                </div>
            </div>

            <flux:textarea wire:model="description" label="Description" rows="2" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="level" type="number" min="0" label="Level" description="Opsional, misal 10 = Staff, 40 = Manager." />
                <flux:input wire:model="sort_order" type="number" min="0" label="Sort order" />
            </div>

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

    <flux:modal name="position-status" class="w-full max-w-md">
        @if ($this->statusTarget)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->statusTarget->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $this->statusTarget->name }}?</flux:heading>
                    <flux:text class="mt-2">
                        {{ $this->statusTarget->is_active
                            ? 'Position tidak dapat dipilih untuk keanggotaan baru. Keanggotaan yang sudah memakai position ini tetap tersimpan.'
                            : 'Position dapat kembali dipilih untuk keanggotaan.' }}
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
