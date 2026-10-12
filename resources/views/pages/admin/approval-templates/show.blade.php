<?php

use App\Domain\Access\PermissionRegistry;
use App\Domain\Approval\Actions\ManageApprovalTemplates;
use App\Enums\ApproverType;
use App\Enums\WorkflowEntity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ApprovalTemplate;
use App\Models\ApprovalTemplateStep;
use App\Models\Department;
use App\Models\ProjectRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $templateId;

    public string $name = '';

    public string $entity_type = '';

    public string $description = '';

    #[Locked]
    public ?int $editingStepId = null;

    /** @var array{name: string, approver_type: string, user_id: int|null, department_id: string, project_role_id: string, permission: string, minimum_approvals: string, allow_self_approval: bool, is_required: bool} */
    public array $step = [];

    public function mount(ApprovalTemplate $approvalTemplate): void
    {
        $this->authorize('view', $approvalTemplate);
        $this->templateId = $approvalTemplate->id;
        $this->name = $approvalTemplate->name;
        $this->entity_type = (string) $approvalTemplate->entity_type?->value;
        $this->description = (string) $approvalTemplate->description;
        $this->resetStep();
    }

    #[Computed]
    public function template(): ApprovalTemplate
    {
        return ApprovalTemplate::query()->with('steps')->withCount(['transitions', 'requests'])->findOrFail($this->templateId);
    }

    /**
     * @return array{departments: Collection<int, Department>, roles: Collection<int, ProjectRole>, permissions: list<string>}
     */
    #[Computed]
    public function options(): array
    {
        return [
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'roles' => ProjectRole::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'permissions' => PermissionRegistry::all(),
        ];
    }

    public function describe(ApprovalTemplateStep $step): string
    {
        $config = $step->approver_config ?? [];

        return match ($step->approver_type) {
            ApproverType::SpecificUser => User::query()->whereKey((int) ($config['user_id'] ?? 0))->value('name') ?? 'User tidak ditemukan',
            ApproverType::DepartmentLeader => isset($config['department_id']) ? 'Leader '.Department::query()->whereKey((int) $config['department_id'])->value('name') : 'Leader department record (Ticket: department saat ini)',
            ApproverType::ProjectRole => 'Project role '.ProjectRole::query()->whereKey((int) ($config['project_role_id'] ?? 0))->value('name'),
            ApproverType::Permission => 'Pengguna dengan izin '.($config['permission'] ?? '—').' yang dapat melihat record',
            default => $step->approver_type->label(),
        };
    }

    public function saveDetails(ManageApprovalTemplates $templates): void
    {
        $this->authorize('manage', $this->template);
        $data = $this->validate(['name' => ['required', 'string', 'max:120'], 'entity_type' => ['nullable', Rule::enum(WorkflowEntity::class)], 'description' => ['nullable', 'string', 'max:1000']]);
        $templates->save($this->template, $data, auth()->user());
        $this->notify('success', 'Template diperbarui.');
        unset($this->template);
    }

    public function toggleActive(ManageApprovalTemplates $templates): void
    {
        $this->authorize('manage', $this->template);
        $templates->setActive($this->template, ! $this->template->is_active);
        $this->notify('success', 'Status template diperbarui.');
        unset($this->template);
    }

    public function openStep(?int $stepId = null): void
    {
        $this->authorize('manage', $this->template);
        $this->resetStep();
        $this->editingStepId = null;

        if ($stepId !== null) {
            $model = $this->template->steps->firstWhere('id', $stepId) ?? abort(404);
            $config = $model->approver_config ?? [];
            $this->editingStepId = $model->id;
            $this->step = [
                'name' => $model->name,
                'approver_type' => $model->approver_type->value,
                'user_id' => isset($config['user_id']) ? (int) $config['user_id'] : null,
                'department_id' => (string) ($config['department_id'] ?? ''),
                'project_role_id' => (string) ($config['project_role_id'] ?? ''),
                'permission' => (string) ($config['permission'] ?? ''),
                'minimum_approvals' => (string) $model->minimum_approvals,
                'allow_self_approval' => $model->allow_self_approval,
                'is_required' => $model->is_required,
            ];
        }

        $this->resetValidation();
        Flux::modal('approval-step')->show();
    }

    public function saveStep(ManageApprovalTemplates $templates): void
    {
        $this->authorize('manage', $this->template);
        $this->validate([
            'step.name' => ['required', 'string', 'max:120'],
            'step.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'step.minimum_approvals' => ['required', 'integer', 'min:1', 'max:10'],
        ], attributes: ['step.name' => 'Step name', 'step.approver_type' => 'Approver type', 'step.minimum_approvals' => 'Minimum approvals']);

        $templates->saveStep($this->template, $this->editingStepId ? $this->template->steps->firstWhere('id', $this->editingStepId) : null, [
            'name' => $this->step['name'],
            'approver_type' => $this->step['approver_type'],
            'user_id' => $this->step['user_id'],
            'department_id' => filled($this->step['department_id']) ? (int) $this->step['department_id'] : null,
            'project_role_id' => filled($this->step['project_role_id']) ? (int) $this->step['project_role_id'] : null,
            'permission' => $this->step['permission'] ?: null,
            'minimum_approvals' => (int) $this->step['minimum_approvals'],
            'allow_self_approval' => $this->step['allow_self_approval'],
            'is_required' => $this->step['is_required'],
        ]);

        Flux::modal('approval-step')->close();
        $this->notify('success', 'Tahap approval disimpan.');
        unset($this->template);
    }

    public function removeStep(int $stepId, ManageApprovalTemplates $templates): void
    {
        $this->authorize('manage', $this->template);
        $templates->removeStep($this->template, $this->template->steps->firstWhere('id', $stepId) ?? abort(404));
        $this->notify('success', 'Tahap dihapus.');
        unset($this->template);
    }

    private function resetStep(): void
    {
        $this->step = ['name' => '', 'approver_type' => ApproverType::DepartmentLeader->value, 'user_id' => null, 'department_id' => '', 'project_role_id' => '', 'permission' => '', 'minimum_approvals' => '1', 'allow_self_approval' => false, 'is_required' => true];
    }

    public function render()
    {
        return $this->view()->title($this->template->name);
    }
}; ?>

@php($template = $this->template)

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="$template->name" :description="($template->entity_type?->label() ?? 'Any entity').' · '.$template->transitions_count.' aksi workflow · '.$template->requests_count.' permintaan'">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.approval-templates.index')" wire:navigate>Approval Templates</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $template->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <flux:button wire:click="toggleActive">{{ $template->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($template->requests_count > 0)
        <flux:callout icon="information-circle" class="mb-6" heading="Template sudah dipakai">
            <flux:callout.text>Permintaan yang sedang berjalan memakai salinan tahapnya sendiri. Perubahan di sini hanya berlaku untuk permintaan baru.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">Steps</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="openStep">Add step</flux:button>
            </div>
            <ol class="space-y-3">
                @forelse ($template->steps as $step)
                    <li wire:key="ts-{{ $step->id }}" class="flex items-start justify-between gap-3 rounded-lg border border-zinc-100 px-4 py-3">
                        <div class="min-w-0">
                            <div class="font-medium text-zinc-900">Step {{ $step->step_order }} · {{ $step->name }}</div>
                            <div class="text-sm text-zinc-600">{{ $this->describe($step) }}</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if ($step->minimum_approvals > 1)<flux:badge size="sm" color="zinc">{{ $step->minimum_approvals }} approvals</flux:badge>@endif
                                @if ($step->allow_self_approval)<flux:badge size="sm" color="amber">Self approval allowed</flux:badge>@endif
                                @unless ($step->is_required)<flux:badge size="sm" color="zinc">Optional</flux:badge>@endunless
                            </div>
                        </div>
                        <div class="flex shrink-0">
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openStep({{ $step->id }})" aria-label="Edit step" />
                            <flux:button size="xs" variant="ghost" icon="trash" aria-label="Remove step" x-on:click="hwConfirm({ title: 'Hapus tahap?', confirmText: 'Hapus', danger: true }).then(ok => ok && $wire.removeStep({{ $step->id }}))" />
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-zinc-500">Belum ada tahap. Tambahkan minimal satu tahap wajib.</li>
                @endforelse
            </ol>
        </div>
        <flux:card class="h-fit">
            <flux:heading size="lg">General</flux:heading>
            <form wire:submit="saveDetails" class="mt-4 space-y-4">
                <flux:input wire:model="name" label="Name" />
                <flux:select wire:model="entity_type" label="Entity Type">
                    <flux:select.option value="">Any entity</flux:select.option>
                    @foreach (App\Enums\WorkflowEntity::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="description" label="Description" rows="2" />
                <div class="flex justify-end"><flux:button type="submit" size="sm" variant="primary">Save</flux:button></div>
            </form>
        </flux:card>
    </div>

    <flux:modal name="approval-step" class="w-full max-w-lg">
        <form wire:submit="saveStep" class="space-y-6">
            <flux:heading size="lg">{{ $editingStepId ? 'Edit step' : 'Add step' }}</flux:heading>
            <flux:input wire:model="step.name" label="Step name" badge="Required" placeholder="Contoh: QA Review" />
            <flux:select wire:model.live="step.approver_type" label="Approver Type">
                @foreach (App\Enums\ApproverType::cases() as $type)
                    <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @switch($step['approver_type'])
                @case('specific_user')
                    <livewire:user-picker wire:model="step.user_id" label="Approver" :required="true" :key="'step-user-'.($editingStepId ?? 'new')" />
                    @break
                @case('department_leader')
                    <flux:select wire:model="step.department_id" label="Department Source">
                        <flux:select.option value="">Department record (Ticket: department saat ini)</flux:select.option>
                        @foreach ($this->options['departments'] as $department)
                            <flux:select.option :value="$department->id" wire:key="sd-{{ $department->id }}">{{ $department->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @break
                @case('project_role')
                    <flux:select wire:model="step.project_role_id" label="Role" placeholder="Pilih project role">
                        @foreach ($this->options['roles'] as $role)
                            <flux:select.option :value="$role->id" wire:key="sr-{{ $role->id }}">{{ $role->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @break
                @case('permission')
                    <flux:select wire:model="step.permission" label="Permission" placeholder="Pilih permission">
                        @foreach ($this->options['permissions'] as $permission)
                            <flux:select.option :value="$permission" wire:key="sp-{{ $permission }}">{{ $permission }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @break
            @endswitch
            <flux:input wire:model="step.minimum_approvals" type="number" min="1" max="10" label="Minimum approvals" description="Contoh: 2 dari beberapa leader. Untuk approver tunggal isi 1." class="max-w-40" />
            <flux:switch wire:model="step.is_required" label="Required step" description="Tahap opsional dilewati bila tidak ada approver." />
            <flux:switch wire:model="step.allow_self_approval" label="Allow self approval" description="Default mati: peminta tidak dapat menyetujui permintaannya sendiri." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save step</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
