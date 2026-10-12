<?php

use App\Domain\Approval\Actions\ManageApprovalTemplates;
use App\Enums\WorkflowEntity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ApprovalTemplate;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approval Templates')] class extends Component {
    use WithFeedback;

    public bool $showCreate = false;

    public string $name = '';

    public string $entity_type = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ApprovalTemplate::class);
    }

    /**
     * @return Collection<int, ApprovalTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return ApprovalTemplate::query()->withCount(['steps', 'transitions', 'requests'])->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('manage', ApprovalTemplate::class);
        $this->reset('name', 'entity_type', 'description');
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function save(ManageApprovalTemplates $templates): void
    {
        $this->authorize('manage', ApprovalTemplate::class);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'entity_type' => ['nullable', Rule::enum(WorkflowEntity::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['name' => 'Name', 'entity_type' => 'Entity']);

        $template = $templates->save(null, $data, auth()->user());

        $this->notifyAfterRedirect('success', 'Template dibuat. Tambahkan tahap approval.');
        $this->redirectRoute('admin.approval-templates.show', $template, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Approval Templates" description="Urutan persetujuan yang dapat dipasang pada aksi workflow. Perubahan hanya berlaku untuk permintaan baru.">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">New Template</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table-card>
        @if ($this->templates->isEmpty())
            <x-ui.empty-state icon="clipboard-document-check" title="Belum ada template approval." description="Buat template, lalu pilih pada aksi workflow yang membutuhkan persetujuan." />
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="ps-5">Name</flux:table.column>
                    <flux:table.column>Entity</flux:table.column>
                    <flux:table.column>Steps</flux:table.column>
                    <flux:table.column>Active</flux:table.column>
                    <flux:table.column class="pe-5">Used By</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->templates as $template)
                        <flux:table.row :key="$template->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('admin.approval-templates.show', $template) }}')">
                            <flux:table.cell class="ps-5"><a href="{{ route('admin.approval-templates.show', $template) }}" wire:navigate x-on:click.stop class="font-medium hover:underline">{{ $template->name }}</a></flux:table.cell>
                            <flux:table.cell>{{ $template->entity_type?->label() ?? 'Any' }}</flux:table.cell>
                            <flux:table.cell>{{ $template->steps_count }}</flux:table.cell>
                            <flux:table.cell><x-ui.active-badge :active="$template->is_active" /></flux:table.cell>
                            <flux:table.cell class="pe-5 text-sm text-zinc-600">{{ $template->transitions_count }} aksi workflow · {{ $template->requests_count }} permintaan</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-ui.table-card>

    <flux:modal wire:model.self="showCreate" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">New Approval Template</flux:heading>
            <flux:input wire:model="name" label="Name" badge="Required" placeholder="Contoh: Ticket QA + TS Approval" />
            <flux:select wire:model="entity_type" label="Entity Type" description="Kosongkan agar dapat dipakai di semua entity.">
                <flux:select.option value="">Any entity</flux:select.option>
                @foreach (App\Enums\WorkflowEntity::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="description" label="Description" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
