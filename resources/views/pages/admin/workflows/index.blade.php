<?php

use App\Domain\Workflow\Actions\CreateWorkflow;
use App\Enums\WorkflowEntity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Workflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Workflows')] class extends Component {
    use WithFeedback;

    #[Url(except: '')]
    public string $entity = '';

    public bool $showCreate = false;

    public string $name = '';

    public string $entity_type = 'ticket';

    public string $description = '';

    public string $copy_from_workflow_id = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Workflow::class);
    }

    /**
     * @return Collection<int, Workflow>
     */
    #[Computed]
    public function workflows(): Collection
    {
        return Workflow::query()
            ->when($this->entity !== '', fn ($query) => $query->where('entity_type', $this->entity))
            ->with(['publishedVersion', 'draftVersion'])
            ->orderBy('entity_type')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Workflow>
     */
    #[Computed]
    public function sources(): Collection
    {
        return Workflow::query()->where('entity_type', $this->entity_type)->whereHas('versions')->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
    }

    public function create(): void
    {
        $this->authorize('manage', Workflow::class);
        $this->reset('name', 'description');
        $this->entity_type = $this->entity !== '' ? $this->entity : 'ticket';
        $this->copy_from_workflow_id = (string) Workflow::query()->where('entity_type', $this->entity_type)->where('is_default', true)->value('id');
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function updatedEntityType(): void
    {
        $this->copy_from_workflow_id = (string) Workflow::query()->where('entity_type', $this->entity_type)->where('is_default', true)->value('id');
    }

    public function save(CreateWorkflow $createWorkflow): void
    {
        $this->authorize('manage', Workflow::class);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'entity_type' => ['required', Rule::enum(WorkflowEntity::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'copy_from_workflow_id' => ['nullable', 'integer'],
        ], attributes: ['name' => 'Workflow name', 'entity_type' => 'Entity']);

        $workflow = $createWorkflow->handle([...$validated, 'copy_from_workflow_id' => filled($validated['copy_from_workflow_id']) ? (int) $validated['copy_from_workflow_id'] : null], auth()->user());

        $this->notifyAfterRedirect('success', 'Workflow '.$workflow->name.' dibuat sebagai draft.');
        $this->redirectRoute('admin.workflows.show', ['workflow' => $workflow, 'tab' => 'builder'], navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Workflows" description="Proses yang mengatur aksi status Project, Task, dan Ticket. Versi yang sudah dipublikasikan tidak berubah; data yang berjalan tetap memakai versinya.">
        <x-slot:actions>
            @can('manage', App\Models\Workflow::class)
                <flux:button variant="primary" icon="plus" wire:click="create">New Workflow</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 w-full sm:w-56">
        <flux:select wire:model.live="entity" aria-label="Entity">
            <flux:select.option value="">All entities</flux:select.option>
            @foreach (App\Enums\WorkflowEntity::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <x-ui.table-card class="hidden md:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="ps-5">Workflow</flux:table.column>
                <flux:table.column>Entity</flux:table.column>
                <flux:table.column>Published Version</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column class="pe-5">Last Updated</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->workflows as $workflow)
                    <flux:table.row :key="$workflow->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('admin.workflows.show', $workflow) }}')">
                        <flux:table.cell class="ps-5">
                            <a href="{{ route('admin.workflows.show', $workflow) }}" wire:navigate x-on:click.stop class="font-medium text-zinc-900 hover:underline">{{ $workflow->name }}</a>
                            @if ($workflow->is_default)
                                <div class="text-xs text-indigo-600">Default for new {{ $workflow->entity_type->label() }}s</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $workflow->entity_type->label() }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $workflow->publishedVersion?->label() ?? '—' }}
                            @if ($workflow->draftVersion)
                                <flux:badge size="sm" color="amber" class="ms-1">Draft {{ $workflow->draftVersion->label() }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if (! $workflow->is_active)
                                <flux:badge size="sm" color="zinc">Inactive</flux:badge>
                            @elseif ($workflow->publishedVersion)
                                <flux:badge size="sm" color="green">Published</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">Not published</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="pe-5 text-sm text-zinc-500">{{ $workflow->updated_at?->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5"><x-ui.empty-state icon="arrow-path-rounded-square" title="Belum ada workflow." /></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-ui.table-card>

    <div class="space-y-3 md:hidden">
        @foreach ($this->workflows as $workflow)
            <a href="{{ route('admin.workflows.show', $workflow) }}" wire:navigate wire:key="wc-{{ $workflow->id }}" class="block rounded-xl border border-zinc-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-zinc-900">{{ $workflow->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $workflow->entity_type->label() }} · {{ $workflow->publishedVersion?->label() ?? 'Not published' }}</div>
                    </div>
                    @if ($workflow->is_default)
                        <flux:badge size="sm" color="indigo">Default</flux:badge>
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    @can('manage', App\Models\Workflow::class)
        <flux:modal wire:model.self="showCreate" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">New Workflow</flux:heading>
                    <flux:text class="mt-1">Workflow baru dibuat sebagai draft. Publikasikan setelah state dan transition siap.</flux:text>
                </div>
                <flux:input wire:model="name" label="Workflow Name" badge="Required" placeholder="Contoh: Bug Ticket Workflow" />
                <flux:select wire:model.live="entity_type" label="Entity Type">
                    @foreach (App\Enums\WorkflowEntity::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="copy_from_workflow_id" label="Start from" description="Salin state & transition dari workflow lain agar tidak mulai dari nol.">
                    <flux:select.option value="">Empty workflow</flux:select.option>
                    @foreach ($this->sources as $source)
                        <flux:select.option :value="$source->id" wire:key="src-{{ $source->id }}">Copy of {{ $source->name }}{{ $source->is_default ? ' (default)' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="description" label="Description" rows="2" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Create draft</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
