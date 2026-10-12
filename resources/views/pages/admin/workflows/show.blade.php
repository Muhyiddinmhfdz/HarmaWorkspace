<?php

use App\Domain\Access\PermissionRegistry;
use App\Domain\Workflow\Actions\CreateDraftVersion;
use App\Domain\Workflow\Actions\CreateWorkflow;
use App\Domain\Workflow\Actions\DeleteDraftVersion;
use App\Domain\Workflow\Actions\ManageWorkflowDraft;
use App\Domain\Workflow\Actions\PublishWorkflowVersion;
use App\Domain\Workflow\Actions\UpdateWorkflow;
use App\Domain\Workflow\Handlers\TransitionHandlers;
use App\Domain\Workflow\Support\WorkflowActorResolver;
use App\Domain\Workflow\Support\WorkflowVersionValidator;
use App\Enums\WorkflowRuleType;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ApprovalTemplate;
use App\Models\Status;
use App\Models\Workflow;
use App\Models\WorkflowState;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    public const array TABS = ['overview' => 'Overview', 'builder' => 'States & Transitions', 'versions' => 'Versions', 'usage' => 'Usage'];

    #[Locked]
    public int $workflowId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(as: 'version', except: null)]
    public ?int $versionId = null;

    public string $name = '';

    public string $description = '';

    #[Locked]
    public ?int $editingStateId = null;

    public string $stateStatusId = '';

    public string $stateLabel = '';

    public bool $stateInitial = false;

    #[Locked]
    public ?int $editingTransitionId = null;

    /** @var array{from_state_id: string, to_state_id: string, action_label: string, operation: string, roles: list<string>, permissions: list<string>, requirements: list<string>, approval_template_id: string, confirmation_message: string} */
    public array $transition = [];

    public function mount(Workflow $workflow): void
    {
        $this->authorize('view', $workflow);
        $this->workflowId = $workflow->id;
        $this->name = $workflow->name;
        $this->description = (string) $workflow->description;

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }

        $this->resetTransitionForm();
    }

    #[Computed]
    public function workflow(): Workflow
    {
        return Workflow::query()->with(['publishedVersion.publisher:id,name', 'draftVersion'])->findOrFail($this->workflowId);
    }

    /**
     * @return Collection<int, WorkflowVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return WorkflowVersion::query()->where('workflow_id', $this->workflowId)->with('publisher:id,name')->orderByDesc('version_number')->get();
    }

    /**
     * Selected version: explicit, else the open draft, else the published one.
     */
    #[Computed]
    public function version(): ?WorkflowVersion
    {
        $id = $this->versionId ?? $this->workflow->draftVersion?->id ?? $this->workflow->publishedVersion?->id;

        return $id === null ? null : WorkflowVersion::query()
            ->where('workflow_id', $this->workflowId)
            ->with(['states.status', 'transitions' => fn ($query) => $query->with(['rules', 'fromState.status', 'toState.status', 'approvalTemplate'])])
            ->find($id);
    }

    public function editable(): bool
    {
        return $this->version?->isDraft() === true && auth()->user()->can('manage', $this->workflow);
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, summary: array{initial: string|null, states: int, transitions: int, approvals: int}}|null
     */
    #[Computed]
    public function report(): ?array
    {
        return $this->version?->isDraft() ? app(WorkflowVersionValidator::class)->validate($this->version) : null;
    }

    /**
     * @return array<int, int> version id => records
     */
    #[Computed]
    public function usage(): array
    {
        $model = $this->workflow->entity_type->modelClass();

        return $model::query()
            ->whereIn('workflow_version_id', $this->versions->pluck('id'))
            ->selectRaw('workflow_version_id, COUNT(*) as aggregate')
            ->groupBy('workflow_version_id')
            ->pluck('aggregate', 'workflow_version_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array{statuses: Collection<int, Status>, roles: array<string, string>, permissions: array<string, string>, operations: array<string, string>, templates: Collection<int, ApprovalTemplate>, requirements: array<string, string>}
     */
    #[Computed]
    public function options(): array
    {
        $entity = $this->workflow->entity_type;
        $group = PermissionRegistry::groups()[$entity->value]['permissions'] ?? [];

        return [
            'statuses' => Status::query()->forScope($entity->statusScope())->active()->orderBy('sort_order')->get(),
            'roles' => app(WorkflowActorResolver::class)->available($entity),
            'permissions' => $group,
            'operations' => app(TransitionHandlers::class)->for($entity)->operations(),
            'templates' => ApprovalTemplate::query()->where('is_active', true)->where(fn ($query) => $query->whereNull('entity_type')->orWhere('entity_type', $entity->value))->orderBy('name')->get(['id', 'name']),
            'requirements' => collect(ManageWorkflowDraft::REQUIREMENTS)->mapWithKeys(fn (string $type): array => [$type => WorkflowRuleType::from($type)->label()])->all(),
        ];
    }

    public function selectVersion(int $versionId): void
    {
        abort_unless($this->versions->contains('id', $versionId), 404);
        $this->versionId = $versionId;
        $this->tab = 'builder';
        unset($this->version, $this->report);
    }

    public function saveDetails(UpdateWorkflow $update): void
    {
        $this->authorize('manage', $this->workflow);
        $data = $this->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:1000']]);
        $update->details($this->workflow, $data, auth()->user());
        $this->notify('success', 'Workflow diperbarui.');
        $this->refresh();
    }

    public function makeDefault(UpdateWorkflow $update): void
    {
        $this->authorize('manage', $this->workflow);
        $update->makeDefault($this->workflow, auth()->user());
        $this->notify('success', $this->workflow->name.' kini dipakai untuk '.$this->workflow->entity_type->label().' baru.');
        $this->refresh();
    }

    public function toggleActive(UpdateWorkflow $update): void
    {
        $this->authorize('manage', $this->workflow);
        $update->setActive($this->workflow, ! $this->workflow->is_active, auth()->user());
        $this->notify('success', 'Status workflow diperbarui.');
        $this->refresh();
    }

    public function duplicate(CreateWorkflow $create): void
    {
        $this->authorize('manage', $this->workflow);
        $copy = $create->handle(['name' => 'Copy of '.$this->workflow->name, 'entity_type' => $this->workflow->entity_type->value, 'description' => $this->workflow->description, 'copy_from_workflow_id' => $this->workflow->id], auth()->user());
        $this->notifyAfterRedirect('success', 'Workflow diduplikasi sebagai draft.');
        $this->redirectRoute('admin.workflows.show', ['workflow' => $copy, 'tab' => 'builder'], navigate: true);
    }

    public function createDraft(CreateDraftVersion $createDraft): void
    {
        $this->authorize('manage', $this->workflow);
        $draft = $createDraft->handle($this->workflow, auth()->user());
        $this->versionId = $draft->id;
        $this->tab = 'builder';
        $this->notify('success', 'Draft '.$draft->label().' dibuat dari versi yang berlaku.');
        $this->refresh();
    }

    public function deleteDraft(int $versionId, DeleteDraftVersion $delete): void
    {
        $this->authorize('manage', $this->workflow);
        $version = WorkflowVersion::query()->where('workflow_id', $this->workflowId)->findOrFail($versionId);
        $delete->handle($version);
        $this->versionId = null;
        $this->notify('success', 'Draft dihapus.');
        $this->refresh();
    }

    public function publish(PublishWorkflowVersion $publish): void
    {
        $this->authorize('publish', $this->workflow);
        $version = $this->version ?? abort(404);
        $publish->handle($version, auth()->user());
        $this->versionId = null;
        $this->notify('success', 'Versi '.$version->label().' dipublikasikan. Data baru memakai versi ini; data berjalan tetap memakai versinya.');
        $this->refresh();
    }

    // States

    public function openState(?int $stateId = null): void
    {
        abort_unless($this->editable(), 403);
        $state = $stateId ? $this->findState($stateId) : null;
        $this->editingStateId = $state?->id;
        $this->stateStatusId = (string) $state?->status_id;
        $this->stateLabel = (string) $state?->label_override;
        $this->stateInitial = (bool) $state?->is_initial;
        $this->resetValidation();
        Flux::modal('workflow-state')->show();
    }

    public function saveState(ManageWorkflowDraft $draft): void
    {
        abort_unless($this->editable(), 403);
        $this->validate(['stateStatusId' => ['required', 'integer'], 'stateLabel' => ['nullable', 'string', 'max:100']], ['stateStatusId.required' => 'Pilih status.']);
        $draft->saveState($this->version, $this->editingStateId ? $this->findState($this->editingStateId) : null, [
            'status_id' => (int) $this->stateStatusId,
            'label_override' => $this->stateLabel,
            'is_initial' => $this->stateInitial,
        ]);
        Flux::modal('workflow-state')->close();
        $this->notify('success', 'State disimpan.');
        $this->refresh();
    }

    public function removeState(int $stateId, ManageWorkflowDraft $draft): void
    {
        abort_unless($this->editable(), 403);
        $draft->removeState($this->version, $this->findState($stateId));
        $this->notify('success', 'State dan aksi yang memakainya dihapus dari draft.');
        $this->refresh();
    }

    // Transitions

    public function openTransition(?int $transitionId = null): void
    {
        abort_unless($this->editable(), 403);
        $this->resetTransitionForm();
        $this->editingTransitionId = null;

        if ($transitionId !== null) {
            $transition = $this->findTransition($transitionId);
            $this->editingTransitionId = $transition->id;
            $this->transition = [
                'from_state_id' => (string) $transition->from_state_id,
                'to_state_id' => (string) $transition->to_state_id,
                'action_label' => $transition->action_label,
                'operation' => $transition->operation,
                'roles' => $transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? [],
                'permissions' => $transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [],
                'requirements' => $transition->rules->filter(fn ($rule): bool => ! $rule->type->isWhoRule())->map(fn ($rule): string => $rule->type->value)->values()->all(),
                'approval_template_id' => (string) $transition->approval_template_id,
                'confirmation_message' => (string) $transition->confirmation_message,
            ];
        }

        $this->resetValidation();
        Flux::modal('workflow-transition-edit')->show();
    }

    public function saveTransition(ManageWorkflowDraft $draft): void
    {
        abort_unless($this->editable(), 403);
        $this->validate([
            'transition.from_state_id' => ['required', 'integer'],
            'transition.to_state_id' => ['required', 'integer', 'different:transition.from_state_id'],
            'transition.action_label' => ['required', 'string', 'max:100'],
            'transition.operation' => ['required', 'string'],
            'transition.roles' => ['array'],
            'transition.permissions' => ['array'],
            'transition.requirements' => ['array'],
            'transition.approval_template_id' => ['nullable', 'integer'],
            'transition.confirmation_message' => ['nullable', 'string', 'max:500'],
        ], ['transition.to_state_id.different' => 'State tujuan harus berbeda dari state asal.'], ['transition.from_state_id' => 'From', 'transition.to_state_id' => 'To', 'transition.action_label' => 'Action name']);

        $draft->saveTransition($this->version, $this->editingTransitionId ? $this->findTransition($this->editingTransitionId) : null, [
            'from_state_id' => (int) $this->transition['from_state_id'],
            'to_state_id' => (int) $this->transition['to_state_id'],
            'action_label' => $this->transition['action_label'],
            'operation' => $this->transition['operation'],
            'roles' => array_values($this->transition['roles']),
            'permissions' => array_values($this->transition['permissions']),
            'requirements' => array_values($this->transition['requirements']),
            'approval_template_id' => filled($this->transition['approval_template_id']) ? (int) $this->transition['approval_template_id'] : null,
            'confirmation_message' => $this->transition['confirmation_message'],
        ], auth()->user());

        Flux::modal('workflow-transition-edit')->close();
        $this->notify('success', 'Aksi disimpan.');
        $this->refresh();
    }

    public function removeTransition(int $transitionId, ManageWorkflowDraft $draft): void
    {
        abort_unless($this->editable(), 403);
        $draft->removeTransition($this->version, $this->findTransition($transitionId));
        $this->notify('success', 'Aksi dihapus dari draft.');
        $this->refresh();
    }

    public function whoSummary(WorkflowTransition $transition): string
    {
        $roles = $this->options['roles'];
        $parts = array_map(fn (string $role): string => $roles[$role] ?? $role, $transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? []);
        $permissions = $transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [];

        if ($permissions !== []) {
            $parts[] = 'izin '.implode(' / ', $permissions);
        }

        return $parts === [] ? ($transition->operation === 'assign' ? 'Mengikuti hak Assign PIC' : 'Belum ditentukan') : implode(', ', $parts);
    }

    private function findState(int $id): WorkflowState
    {
        return WorkflowState::query()->where('workflow_version_id', $this->version?->id)->findOrFail($id);
    }

    private function findTransition(int $id): WorkflowTransition
    {
        return WorkflowTransition::query()->where('workflow_version_id', $this->version?->id)->with('rules')->findOrFail($id);
    }

    private function resetTransitionForm(): void
    {
        $this->transition = ['from_state_id' => '', 'to_state_id' => '', 'action_label' => '', 'operation' => 'move', 'roles' => [], 'permissions' => [], 'requirements' => [], 'approval_template_id' => '', 'confirmation_message' => ''];
    }

    private function refresh(): void
    {
        unset($this->workflow, $this->versions, $this->version, $this->report, $this->usage);
    }

    public function render()
    {
        return $this->view()->title($this->workflow->name);
    }
}; ?>

@php($workflow = $this->workflow)
@php($version = $this->version)
@php($editable = $this->editable())

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$workflow->name" :description="$workflow->entity_type->label().' workflow'.($workflow->is_default ? ' · Default for new '.$workflow->entity_type->label().'s' : '')">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.workflows.index')" wire:navigate>Workflows</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $workflow->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('manage', $workflow)
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon:trailing="chevron-down">More</flux:button>
                    <flux:menu>
                        @if (! $workflow->draftVersion)
                            <flux:menu.item icon="document-duplicate" wire:click="createDraft">Create draft version</flux:menu.item>
                        @endif
                        <flux:menu.item icon="square-2-stack" wire:click="duplicate">Duplicate workflow</flux:menu.item>
                        @if (! $workflow->is_default && $workflow->is_active && $workflow->publishedVersion)
                            <flux:menu.item icon="star" x-on:click="hwConfirm({ title: 'Jadikan default?', text: @js($workflow->entity_type->label().' baru akan memakai workflow ini. Data yang sudah berjalan tidak berubah.'), confirmText: 'Jadikan default' }).then(ok => ok && $wire.makeDefault())">Make default</flux:menu.item>
                        @endif
                        @unless ($workflow->is_default)
                            <flux:menu.item :icon="$workflow->is_active ? 'no-symbol' : 'check-circle'" wire:click="toggleActive">{{ $workflow->is_active ? 'Deactivate' : 'Activate' }}</flux:menu.item>
                        @endunless
                    </flux:menu>
                </flux:dropdown>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <nav class="flex min-w-max gap-1 border-b border-zinc-200">
            @foreach ($this::TABS as $key => $label)
                <button type="button" wire:key="wt-{{ $key }}" wire:click="$set('tab', '{{ $key }}')"
                    @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap', 'border-accent text-zinc-900' => $tab === $key, 'border-transparent text-zinc-500 hover:text-zinc-800' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <flux:card>
                    <flux:heading size="lg">General</flux:heading>
                    @can('manage', $workflow)
                        <form wire:submit="saveDetails" class="mt-4 space-y-4">
                            <flux:input wire:model="name" label="Workflow Name" />
                            <flux:textarea wire:model="description" label="Description" rows="3" />
                            <div class="flex justify-end"><flux:button type="submit" variant="primary" size="sm">Save</flux:button></div>
                        </form>
                    @else
                        <flux:text class="mt-2">{{ $workflow->description ?: 'Tanpa deskripsi.' }}</flux:text>
                    @endcan
                </flux:card>
            </div>
            <aside class="h-fit rounded-xl border border-zinc-200 bg-white">
                <dl class="divide-y divide-zinc-100 px-5">
                    <x-ui.detail-item label="Entity" class="sm:flex-col sm:gap-1">{{ $workflow->entity_type->label() }}</x-ui.detail-item>
                    <x-ui.detail-item label="Usage" class="sm:flex-col sm:gap-1">
                        @if ($workflow->is_default)
                            <flux:badge size="sm" color="indigo">Default for new {{ $workflow->entity_type->label() }}s</flux:badge>
                        @else
                            Dipilih manual
                        @endif
                    </x-ui.detail-item>
                    <x-ui.detail-item label="Status" class="sm:flex-col sm:gap-1">{{ $workflow->is_active ? ($workflow->publishedVersion ? 'Active & usable' : 'Active, not published yet') : 'Inactive' }}</x-ui.detail-item>
                    <x-ui.detail-item label="Published version" class="sm:flex-col sm:gap-1">
                        @if ($workflow->publishedVersion)
                            {{ $workflow->publishedVersion->label() }} · {{ $workflow->publishedVersion->published_at?->translatedFormat('d M Y') }}{{ $workflow->publishedVersion->publisher ? ' oleh '.$workflow->publishedVersion->publisher->name : '' }}
                        @endif
                    </x-ui.detail-item>
                    <x-ui.detail-item label="Code" class="sm:flex-col sm:gap-1"><span class="font-mono text-xs">{{ $workflow->code }}</span></x-ui.detail-item>
                </dl>
            </aside>
        </div>
    @elseif ($tab === 'builder')
        @if ($version === null)
            <x-ui.table-card><x-ui.empty-state icon="arrow-path-rounded-square" title="Belum ada versi." /></x-ui.table-card>
        @else
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <flux:badge :color="$version->status->color()">{{ $version->label() }} · {{ $version->status->label() }}</flux:badge>
                @if (! $version->isDraft())
                    <flux:text>Versi ini {{ $version->status->value === 'published' ? 'sedang berlaku' : 'sudah diarsipkan' }} dan tidak dapat diubah.</flux:text>
                    @if (! $workflow->draftVersion && auth()->user()->can('manage', $workflow))
                        <flux:button size="sm" icon="document-duplicate" wire:click="createDraft">Create draft to edit</flux:button>
                    @elseif ($workflow->draftVersion && $workflow->draftVersion->id !== $version->id)
                        <flux:button size="sm" wire:click="selectVersion({{ $workflow->draftVersion->id }})">Open draft {{ $workflow->draftVersion->label() }}</flux:button>
                    @endif
                @endif
            </div>

            @if ($report = $this->report)
                <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <flux:heading size="lg">Validate & Publish</flux:heading>
                            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-600">
                                <span>Initial: <span class="font-medium text-zinc-900">{{ $report['summary']['initial'] ?? '—' }}</span></span>
                                <span>{{ $report['summary']['states'] }} states</span>
                                <span>{{ $report['summary']['transitions'] }} transitions</span>
                                <span>Approval: {{ $report['summary']['approvals'] }} transitions</span>
                                <span>Warnings: {{ count($report['warnings']) }}</span>
                            </div>
                        </div>
                        @can('publish', $workflow)
                            <flux:button variant="primary" icon="rocket-launch" :disabled="$report['errors'] !== []"
                                x-on:click="hwConfirm({ title: 'Publish Workflow Version?', text: @js($workflow->entity_type->label().' baru akan menggunakan versi ini. Data yang sudah berjalan tetap memakai workflow versi sebelumnya.'), confirmText: 'Publish' }).then(ok => ok && $wire.publish())">Publish {{ $version->label() }}</flux:button>
                        @endcan
                    </div>
                    @if ($report['errors'] !== [])
                        <flux:callout variant="danger" icon="x-circle" class="mt-4" heading="Perlu diperbaiki sebelum publish">
                            <flux:callout.text><ul class="list-disc ps-4">@foreach ($report['errors'] as $error)<li wire:key="err-{{ $loop->index }}">{{ $error }}</li>@endforeach</ul></flux:callout.text>
                        </flux:callout>
                    @endif
                    @if ($report['warnings'] !== [])
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" heading="Perhatikan">
                            <flux:callout.text><ul class="list-disc ps-4">@foreach ($report['warnings'] as $warning)<li wire:key="warn-{{ $loop->index }}">{{ $warning }}</li>@endforeach</ul></flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <flux:heading size="lg">States</flux:heading>
                        @if ($editable)
                            <flux:button size="sm" icon="plus" wire:click="openState">Add</flux:button>
                        @endif
                    </div>
                    <ol class="space-y-2">
                        @forelse ($version->states as $state)
                            <li wire:key="ws-{{ $state->id }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 px-3 py-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="w-5 text-xs tabular-nums text-zinc-400">{{ $loop->iteration }}.</span>
                                    <flux:badge size="sm" :color="$state->status->color">{{ $state->label() }}</flux:badge>
                                    @if ($state->is_initial)<flux:badge size="sm" color="indigo">Initial</flux:badge>@endif
                                    @if ($state->is_terminal)<flux:badge size="sm" color="zinc">Final</flux:badge>@endif
                                </div>
                                @if ($editable)
                                    <div class="flex shrink-0">
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openState({{ $state->id }})" aria-label="Edit state" />
                                        <flux:button size="xs" variant="ghost" icon="trash" aria-label="Remove state"
                                            x-on:click="hwConfirm({ title: 'Hapus state?', text: 'Aksi yang memakai state ini juga dihapus dari draft.', confirmText: 'Hapus', danger: true }).then(ok => ok && $wire.removeState({{ $state->id }}))" />
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="text-sm text-zinc-500">Belum ada state. Tambahkan status yang dipakai proses ini.</li>
                        @endforelse
                    </ol>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2">
                    <div class="mb-3 flex items-center justify-between">
                        <flux:heading size="lg">Transitions</flux:heading>
                        @if ($editable && $version->states->count() > 1)
                            <flux:button size="sm" icon="plus" wire:click="openTransition">Add action</flux:button>
                        @endif
                    </div>
                    @forelse ($version->transitions->groupBy('from_state_id') as $fromId => $group)
                        <div wire:key="wg-{{ $fromId }}" class="mb-4 last:mb-0">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $group->first()->fromState->label() }}</div>
                            <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-100">
                                @foreach ($group as $item)
                                    <li wire:key="wtr-{{ $item->id }}" class="flex items-start justify-between gap-3 px-3 py-2.5">
                                        <div class="min-w-0 text-sm">
                                            <div class="font-medium text-zinc-900">{{ $item->action_label }} <span class="font-normal text-zinc-500">→ {{ $item->toState->label() }}</span></div>
                                            <div class="text-xs text-zinc-500">Who: {{ $this->whoSummary($item) }}</div>
                                            @php($requirements = $item->rules->reject(fn ($rule) => $rule->type->isWhoRule())->map(fn ($rule) => $rule->type->label()))
                                            @if ($requirements->isNotEmpty() || $item->approvalTemplate || $item->operation !== 'move')
                                                <div class="mt-1 flex flex-wrap gap-1">
                                                    @if ($item->operation !== 'move')<flux:badge size="sm" color="zinc">{{ str_replace('_', ' ', $item->operation) }}</flux:badge>@endif
                                                    @foreach ($requirements as $requirement)<flux:badge size="sm" color="sky" wire:key="rq-{{ $item->id }}-{{ $loop->index }}">{{ $requirement }}</flux:badge>@endforeach
                                                    @if ($item->approvalTemplate)<flux:badge size="sm" color="amber" icon="shield-check">{{ $item->approvalTemplate->name }}</flux:badge>@endif
                                                </div>
                                            @endif
                                        </div>
                                        @if ($editable)
                                            <div class="flex shrink-0">
                                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openTransition({{ $item->id }})" aria-label="Edit action" />
                                                <flux:button size="xs" variant="ghost" icon="trash" aria-label="Remove action"
                                                    x-on:click="hwConfirm({ title: 'Hapus aksi?', confirmText: 'Hapus', danger: true }).then(ok => ok && $wire.removeTransition({{ $item->id }}))" />
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">Belum ada aksi. Tambahkan aksi untuk menghubungkan state.</p>
                    @endforelse
                </div>
            </div>
        @endif
    @elseif ($tab === 'versions')
        <x-ui.table-card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="ps-5">Version</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Published</flux:table.column>
                    <flux:table.column>Records</flux:table.column>
                    <flux:table.column align="end" class="pe-5"><span class="sr-only">Actions</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->versions as $item)
                        <flux:table.row :key="$item->id">
                            <flux:table.cell class="ps-5 font-medium">{{ $item->label() }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$item->status->color()">{{ $item->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-600">{{ $item->published_at?->translatedFormat('d M Y H:i') ?? '—' }}{{ $item->publisher ? ' · '.$item->publisher->name : '' }}</flux:table.cell>
                            <flux:table.cell>{{ $this->usage[$item->id] ?? 0 }}</flux:table.cell>
                            <flux:table.cell align="end" class="pe-5">
                                <flux:button size="sm" variant="ghost" wire:click="selectVersion({{ $item->id }})">Open</flux:button>
                                @if ($item->isDraft() && auth()->user()->can('manage', $workflow) && ($this->usage[$item->id] ?? 0) === 0)
                                    <flux:button size="sm" variant="ghost" icon="trash" aria-label="Delete draft"
                                        x-on:click="hwConfirm({ title: 'Hapus draft?', confirmText: 'Hapus', danger: true }).then(ok => ok && $wire.deleteDraft({{ $item->id }}))" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table-card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->versions->reject(fn ($item) => $item->isDraft()) as $item)
                <div wire:key="wu-{{ $item->id }}" class="rounded-xl border border-zinc-200 bg-white p-5">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ $item->label() }}</flux:heading>
                        <flux:badge size="sm" :color="$item->status->color()">{{ $item->status->label() }}</flux:badge>
                    </div>
                    <div class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($this->usage[$item->id] ?? 0) }}</div>
                    <flux:text size="sm">{{ $workflow->entity_type->label() }}s memakai versi ini</flux:text>
                </div>
            @endforeach
        </div>
    @endif

    @if ($editable)
        <flux:modal name="workflow-state" class="w-full max-w-md">
            <form wire:submit="saveState" class="space-y-6">
                <flux:heading size="lg">{{ $editingStateId ? 'Edit state' : 'Add state' }}</flux:heading>
                <flux:select wire:model="stateStatusId" label="Status" badge="Required" placeholder="Pilih status aktif" description="Status dikelola di Master Data; workflow hanya memilihnya.">
                    @foreach ($this->options['statuses'] as $status)
                        <flux:select.option :value="$status->id" wire:key="so-{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="stateLabel" label="Label (opsional)" description="Kosongkan untuk memakai nama status." />
                <flux:switch wire:model="stateInitial" label="Initial state" description="State awal untuk record baru. Hanya satu." />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="workflow-transition-edit" class="w-full max-w-2xl">
            <form wire:submit="saveTransition" class="space-y-6">
                <flux:heading size="lg">{{ $editingTransitionId ? 'Edit action' : 'Add action' }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="transition.from_state_id" label="From" placeholder="State asal">
                        @foreach ($version->states as $state)
                            <flux:select.option :value="$state->id" wire:key="fs-{{ $state->id }}">{{ $state->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="transition.to_state_id" label="To" placeholder="State tujuan">
                        @foreach ($version->states as $state)
                            <flux:select.option :value="$state->id" wire:key="ts-{{ $state->id }}">{{ $state->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="transition.action_label" label="Action Name" placeholder="Contoh: Send to Review" />
                    <flux:select wire:model="transition.operation" label="Behaviour" description="Efek bisnis yang dijalankan.">
                        @foreach ($this->options['operations'] as $key => $label)
                            <flux:select.option :value="$key" wire:key="op-{{ $key }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:fieldset>
                    <flux:legend>Who Can Do This?</flux:legend>
                    <flux:description>Salah satu role atau permission cukup. Record tetap harus dapat dilihat pengguna.</flux:description>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->options['roles'] as $key => $label)
                            <flux:checkbox wire:model="transition.roles" :value="$key" :label="$label" wire:key="ro-{{ $key }}" />
                        @endforeach
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->options['permissions'] as $key => $label)
                            <flux:checkbox wire:model="transition.permissions" :value="$key" :label="$key" :description="$label" wire:key="pe-{{ $key }}" />
                        @endforeach
                    </div>
                </flux:fieldset>
                <flux:fieldset>
                    <flux:legend>What Is Required?</flux:legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->options['requirements'] as $key => $label)
                            <flux:checkbox wire:model="transition.requirements" :value="$key" :label="$label" wire:key="rq-{{ $key }}" />
                        @endforeach
                    </div>
                </flux:fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="transition.approval_template_id" label="Approval" description="Bila dipilih, status berubah setelah approval selesai.">
                        <flux:select.option value="">Tanpa approval</flux:select.option>
                        @foreach ($this->options['templates'] as $template)
                            <flux:select.option :value="$template->id" wire:key="tp-{{ $template->id }}">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="transition.confirmation_message" label="Confirmation Message" placeholder="Opsional" />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save action</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
