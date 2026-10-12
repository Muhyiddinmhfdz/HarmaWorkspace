<?php

use App\Domain\Client\Actions\CreateClient;
use App\Domain\Client\Actions\UpdateClient;
use App\Domain\Client\Rules\ClientRules;
use App\Domain\MasterData\Actions\ChangeMasterStatus;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Client;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clients')] class extends Component {
    use WithDataTable, WithFeedback;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $pic_name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $notes = '';

    #[Locked]
    public ?int $statusTargetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);

        if (request()->boolean('create') && auth()->user()->can('create', Client::class)) {
            $this->create();
        }
    }

    protected function sortableColumns(): array
    {
        return ['code' => 'code', 'name' => 'name', 'created_at' => 'created_at'];
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('name');
    }

    #[Computed]
    public function clients(): LengthAwarePaginator
    {
        $query = Client::query()
            ->status($this->status)
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', $this->searchPattern())
                    ->orWhere('code', 'like', $this->searchPattern())
                    ->orWhere('pic_name', 'like', $this->searchPattern())
            ));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    #[Computed]
    public function codeLocked(): bool
    {
        return $this->editingId !== null && Client::findOrFail($this->editingId)->isCodeLocked();
    }

    #[Computed]
    public function statusTarget(): ?Client
    {
        return $this->statusTargetId ? Client::find($this->statusTargetId) : null;
    }

    public function create(): void
    {
        $this->authorize('create', Client::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $client = Client::findOrFail($id);
        $this->authorize('update', $client);

        $this->resetForm();
        $this->editingId = $client->id;
        $this->fill($client->only(['code', 'name']));
        $this->pic_name = (string) $client->pic_name;
        $this->phone = (string) $client->phone;
        $this->email = (string) $client->email;
        $this->address = (string) $client->address;
        $this->notes = (string) $client->notes;
        $this->showForm = true;
    }

    public function save(CreateClient $createClient, UpdateClient $updateClient): void
    {
        $client = $this->editingId ? Client::findOrFail($this->editingId) : null;
        $client ? $this->authorize('update', $client) : $this->authorize('create', Client::class);

        $this->code = strtoupper(trim($this->code));
        $validated = array_map(
            fn ($value) => $value === '' ? null : $value,
            $this->validate(ClientRules::rules($client)),
        );

        if ($client) {
            $updateClient->handle($client, $validated);
            $this->notify('success', 'Client berhasil diperbarui.');
        } else {
            $createClient->handle($validated);
            $this->notify('success', 'Client berhasil ditambahkan.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmStatus(int $id): void
    {
        $client = Client::findOrFail($id);
        $this->authorize($client->is_active ? 'deactivate' : 'activate', $client);

        $this->statusTargetId = $client->id;
        Flux::modal('client-status')->show();
    }

    public function changeStatus(ChangeMasterStatus $changeStatus): void
    {
        $client = Client::findOrFail($this->statusTargetId);
        $activate = ! $client->is_active;
        $this->authorize($activate ? 'activate' : 'deactivate', $client);

        $changeStatus->handle($client, $activate);

        Flux::modal('client-status')->close();
        $this->notify('success', $activate ? 'Client berhasil diaktifkan.' : 'Client berhasil dinonaktifkan.');
        $this->statusTargetId = null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'pic_name', 'phone', 'email', 'address', 'notes');
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Clients" description="Data referensi client untuk project dan ticket. Client tidak login ke HarmaWorkspace.">
        <x-slot:actions>
            @can('create', App\Models\Client::class)
                <flux:button variant="primary" icon="plus" wire:click="create">Add Client</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="w-full sm:max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search code, name, or PIC..." clearable aria-label="Search clients" />
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
        @if ($this->clients->isEmpty())
            @if ($search !== '' || $status !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada client yang cocok" description="Coba ubah kata kunci atau filter status.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="briefcase" title="Belum ada Client." description="Tambahkan client agar nantinya project dan ticket dapat dikaitkan dengan client.">
                    @can('create', App\Models\Client::class)
                        <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add Client</flux:button>
                    @endcan
                </x-ui.empty-state>
            @endif
        @else
            <flux:table :paginate="$this->clients" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Client</flux:table.column>
                    <flux:table.column class="max-md:hidden">PIC</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Contact</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->clients as $client)
                        <flux:table.row :key="$client->id">
                            <flux:table.cell class="font-mono text-xs">{{ $client->code }}</flux:table.cell>
                            <flux:table.cell variant="strong">
                                <a href="{{ route('clients.show', $client) }}" wire:navigate class="hover:underline">{{ $client->name }}</a>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $client->pic_name ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">
                                <div class="text-sm">{{ $client->email ?? '—' }}</div>
                                <div class="text-xs text-zinc-500">{{ $client->phone }}</div>
                            </flux:table.cell>
                            <flux:table.cell><x-ui.active-badge :active="$client->is_active" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $client->name }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" :href="route('clients.show', $client)" wire:navigate>View</flux:menu.item>
                                        @can('update', $client)
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $client->id }})">Edit</flux:menu.item>
                                        @endcan
                                        @if ($client->is_active)
                                            @can('deactivate', $client)
                                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmStatus({{ $client->id }})">Deactivate</flux:menu.item>
                                            @endcan
                                        @else
                                            @can('activate', $client)
                                                <flux:menu.item icon="check-circle" wire:click="confirmStatus({{ $client->id }})">Activate</flux:menu.item>
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

    @canany(['client.create', 'client.update'])
    <flux:modal wire:model.self="showForm" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Client' : 'Add Client' }}</flux:heading>
                <flux:text class="mt-1">Hanya code dan nama yang wajib diisi.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="code" label="Code" badge="Required" class="uppercase" maxlength="30" :disabled="$this->codeLocked"
                    :description="$this->codeLocked ? 'Terkunci karena sudah digunakan.' : null" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="name" label="Client name" badge="Required" maxlength="150" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="pic_name" label="PIC name" maxlength="100" />
                <flux:input wire:model="phone" type="tel" label="Phone" maxlength="30" />
                <flux:input wire:model="email" type="email" label="Email" />
            </div>

            <flux:textarea wire:model="address" label="Address" rows="2" />
            <flux:textarea wire:model="notes" label="Notes" rows="3" />

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

    <flux:modal name="client-status" class="w-full max-w-md">
        @if ($this->statusTarget)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->statusTarget->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $this->statusTarget->name }}?</flux:heading>
                    <flux:text class="mt-2">
                        {{ $this->statusTarget->is_active
                            ? 'Client tidak dapat dipilih untuk project atau ticket baru. Data lama tetap tersimpan.'
                            : 'Client dapat kembali dipilih untuk project dan ticket baru.' }}
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
