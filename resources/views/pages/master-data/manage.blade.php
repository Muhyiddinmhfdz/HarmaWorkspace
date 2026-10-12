<?php

use App\Domain\MasterData\Actions\ChangeMasterStatus;
use App\Domain\MasterData\Actions\SaveMasterRecord;
use App\Domain\MasterData\MasterDefinition;
use App\Domain\MasterData\MasterRegistry;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithFeedback;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithDataTable, WithFeedback;

    #[Locked]
    public string $type;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    #[Locked]
    public ?int $statusTargetId = null;

    public function mount(string $type): void
    {
        abort_if(MasterRegistry::find($type) === null, 404);

        $this->type = $type;
        $this->form = $this->definition()->blank();
    }

    public function definition(): MasterDefinition
    {
        return MasterRegistry::find($this->type) ?? abort(404);
    }

    public function canManage(): bool
    {
        return auth()->user()->can('master-data.manage');
    }

    protected function sortableColumns(): array
    {
        return array_merge(
            ['code' => 'code', 'name' => 'name', 'sort_order' => 'sort_order'],
            $this->definition()->hasField('level') ? ['level' => 'level'] : [],
        );
    }

    protected function defaultSort(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $definition = $this->definition();

        return $definition->model::query()
            ->when($definition->scope !== null, fn (Builder $query) => $query->where('scope', $definition->scope?->value));
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        $query = $this->query()
            ->status($this->status)
            ->when($this->hasSearch(), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('name', 'like', $this->searchPattern())->orWhere('code', 'like', $this->searchPattern())
            ));

        return $this->applySorting($query)->paginate($this->perPage);
    }

    private function find(int $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    #[Computed]
    public function codeLocked(): bool
    {
        if ($this->editingId === null) {
            return false;
        }

        $record = $this->find($this->editingId);

        return method_exists($record, 'isCodeLocked') && $record->isCodeLocked();
    }

    /**
     * Options for relation fields (e.g. parent category): active records plus
     * the value currently stored on the edited record.
     *
     * @return array<string, array<int, string>>
     */
    #[Computed]
    public function relationOptions(): array
    {
        $options = [];

        foreach ($this->definition()->relationFields() as $field) {
            $current = (int) ($this->form[$field['name']] ?? 0);
            $model = $field['model'] ?? null;

            $options[$field['name']] = $model === null ? [] : $model::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $current))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return $options;
    }

    /**
     * Display names for relation columns (masters are small lists).
     *
     * @return array<string, array<int, string>>
     */
    #[Computed]
    public function relationNames(): array
    {
        $names = [];

        foreach ($this->definition()->relationFields() as $field) {
            $model = $field['model'] ?? null;
            $names[$field['name']] = $model === null ? [] : $model::query()->pluck('name', 'id')->all();
        }

        return $names;
    }

    #[Computed]
    public function statusTarget(): ?Model
    {
        return $this->statusTargetId ? $this->query()->find($this->statusTargetId) : null;
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);

        $this->resetForm();
        $this->form['sort_order'] = (string) (((int) $this->query()->max('sort_order')) + 10);
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $record = $this->find($id);
        $this->resetForm();
        $this->editingId = $record->getKey();

        foreach (array_keys($this->definition()->blank()) as $field) {
            $value = $record->getAttribute($field);
            $this->form[$field] = is_bool($value) ? $value : (string) $value;
        }

        $this->showForm = true;
    }

    public function save(SaveMasterRecord $saveRecord): void
    {
        abort_unless($this->canManage(), 403);

        $record = $this->editingId ? $this->find($this->editingId) : null;

        $this->form['code'] = strtoupper(trim((string) $this->form['code']));
        $validated = $this->validate($this->definition()->rules($record), attributes: $this->attributeLabels())['form'];

        $saveRecord->handle($this->definition(), $record, $validated);

        $this->notify('success', $this->definition()->singular.($record ? ' berhasil diperbarui.' : ' berhasil ditambahkan.'));
        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmStatus(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $this->statusTargetId = $this->find($id)->getKey();
        Flux::modal('master-status')->show();
    }

    public function changeStatus(ChangeMasterStatus $changeStatus): void
    {
        abort_unless($this->canManage(), 403);

        $record = $this->find((int) $this->statusTargetId);
        $activate = ! $record->getAttribute('is_active');

        $changeStatus->handle($record, $activate);

        Flux::modal('master-status')->close();
        $this->notify('success', $this->definition()->singular.($activate ? ' berhasil diaktifkan.' : ' berhasil dinonaktifkan.'));
        $this->statusTargetId = null;
    }

    /**
     * @return array<string, string>
     */
    private function attributeLabels(): array
    {
        $labels = ['form.code' => 'Code', 'form.name' => 'Name', 'form.description' => 'Description', 'form.sort_order' => 'Sort order'];

        foreach ($this->definition()->fields as $field) {
            $labels['form.'.$field['name']] = $field['label'];
        }

        return $labels;
    }

    private function resetForm(): void
    {
        $this->reset('editingId');
        $this->form = $this->definition()->blank();
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title($this->definition()->label);
    }
}; ?>

@php($definition = $this->definition())

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$definition->label" :description="$definition->description">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('master-data.index')" wire:navigate>Master Data</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $definition->label }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if ($this->canManage())
                <flux:button variant="primary" icon="plus" wire:click="create">Add {{ $definition->singular }}</flux:button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="w-full sm:max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search code or name..." clearable aria-label="Search" />
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
        @if ($this->records->isEmpty())
            @if ($search !== '' || $status !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada data yang cocok" description="Coba ubah kata kunci atau filter status.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state :icon="$definition->icon" :title="'Belum ada '.$definition->singular.'.'" :description="$definition->description">
                    @if ($this->canManage())
                        <flux:button variant="primary" icon="plus" size="sm" wire:click="create">Add {{ $definition->singular }}</flux:button>
                    @endif
                </x-ui.empty-state>
            @endif
        @else
            <flux:table :paginate="$this->records" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
                    @foreach ($definition->relationFields() as $field)
                        <flux:table.column wire:key="rc-{{ $field['name'] }}">{{ $field['label'] }}</flux:table.column>
                    @endforeach
                    @if ($definition->hasField('level'))
                        <flux:table.column sortable :sorted="$sortBy === 'level'" :direction="$sortDirection" wire:click="sort('level')" class="max-md:hidden">Level</flux:table.column>
                    @endif
                    @if ($definition->exclusiveFlags !== [] || $definition->hasField('is_terminal'))
                        <flux:table.column class="max-lg:hidden">Behaviour</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'sort_order'" :direction="$sortDirection" wire:click="sort('sort_order')" class="max-md:hidden">Order</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->records as $record)
                        <flux:table.row :key="$record->getKey()">
                            <flux:table.cell class="font-mono text-xs">{{ $record->code }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($definition->hasField('color'))
                                    <x-ui.master-badge :master="$record" />
                                @else
                                    <span class="font-medium text-zinc-800">{{ $record->name }}</span>
                                @endif
                                @if ($record->description)
                                    <div class="mt-0.5 max-w-sm truncate text-xs text-zinc-500">{{ $record->description }}</div>
                                @endif
                            </flux:table.cell>
                            @foreach ($definition->relationFields() as $field)
                                <flux:table.cell wire:key="rv-{{ $record->getKey() }}-{{ $field['name'] }}">{{ $this->relationNames[$field['name']][$record->getAttribute($field['name'])] ?? '—' }}</flux:table.cell>
                            @endforeach
                            @if ($definition->hasField('level'))
                                <flux:table.cell class="max-md:hidden">{{ $record->level }}</flux:table.cell>
                            @endif
                            @if ($definition->exclusiveFlags !== [] || $definition->hasField('is_terminal'))
                                <flux:table.cell class="max-lg:hidden">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($definition->fields as $field)
                                            @if ($field['type'] === 'boolean' && $record->getAttribute($field['name']))
                                                <flux:badge size="sm" color="zinc" inset="top bottom">{{ $field['label'] }}</flux:badge>
                                            @endif
                                        @endforeach
                                    </div>
                                </flux:table.cell>
                            @endif
                            <flux:table.cell><x-ui.active-badge :active="$record->is_active" /></flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $record->sort_order }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($this->canManage())
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $record->name }}" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $record->getKey() }})">Edit</flux:menu.item>
                                            @if ($record->is_active)
                                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmStatus({{ $record->getKey() }})">Deactivate</flux:menu.item>
                                            @else
                                                <flux:menu.item icon="check-circle" wire:click="confirmStatus({{ $record->getKey() }})">Activate</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-ui.table-card>

    @if ($this->canManage())
        <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Add' }} {{ $definition->singular }}</flux:heading>
                    <flux:text class="mt-1">Nama dapat diubah kapan saja; code dipakai sebagai identitas tetap.</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="form.code" label="Code" badge="Required" class="uppercase" maxlength="30" :disabled="$this->codeLocked"
                        :description="$this->codeLocked ? 'Terkunci karena sudah digunakan.' : null" />
                    <div class="sm:col-span-2">
                        <flux:input wire:model="form.name" label="Name" badge="Required" maxlength="100" />
                    </div>
                </div>

                <flux:textarea wire:model="form.description" label="Description" rows="2" />

                @foreach ($definition->fields as $field)
                    <div wire:key="field-{{ $field['name'] }}">
                        @switch($field['type'])
                            @case('color')
                                <flux:field>
                                    <flux:label>{{ $field['label'] }}</flux:label>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (\App\Domain\MasterData\MasterDefinition::COLORS as $color)
                                            <label wire:key="color-{{ $color }}" class="cursor-pointer">
                                                <input type="radio" wire:model.live="form.{{ $field['name'] }}" value="{{ $color }}" class="peer sr-only" />
                                                <flux:badge :color="$color" size="sm" class="ring-offset-1 peer-checked:ring-2 peer-checked:ring-zinc-800">{{ ucfirst($color) }}</flux:badge>
                                            </label>
                                        @endforeach
                                    </div>
                                    <flux:error name="form.{{ $field['name'] }}" />
                                </flux:field>
                                @break
                            @case('boolean')
                                <flux:switch wire:model="form.{{ $field['name'] }}" :label="$field['label']" :description="$field['help'] ?? null" />
                                @break
                            @case('relation')
                                <flux:select wire:model="form.{{ $field['name'] }}" :label="$field['label']" badge="Required" :placeholder="'Pilih '.strtolower($field['label'])">
                                    @foreach ($this->relationOptions[$field['name']] ?? [] as $optionId => $optionName)
                                        <flux:select.option :value="$optionId" wire:key="ro-{{ $optionId }}">{{ $optionName }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @break
                            @case('number')
                                <flux:input wire:model="form.{{ $field['name'] }}" type="number" min="0" :label="$field['label']" :description="$field['help'] ?? null" class="max-w-48" />
                                @break
                            @default
                                <flux:input wire:model="form.{{ $field['name'] }}" :label="$field['label']" :description="$field['help'] ?? null" />
                        @endswitch
                    </div>
                @endforeach

                <flux:input wire:model="form.sort_order" type="number" min="0" label="Sort order" class="max-w-40" />

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

        <flux:modal name="master-status" class="w-full max-w-md">
            @if ($this->statusTarget)
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ $this->statusTarget->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $this->statusTarget->name }}?</flux:heading>
                        <flux:text class="mt-2">
                            {{ $this->statusTarget->is_active
                                ? 'Data ini tidak dapat dipilih untuk data baru. Data lama yang memakainya tetap valid.'
                                : 'Data ini dapat kembali dipilih.' }}
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
    @endif
</div>
