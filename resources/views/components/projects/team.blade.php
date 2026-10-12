<?php

use App\Domain\Project\Actions\ManageProjectDepartments;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Models\ProjectMember;
use App\Models\ProjectRole;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $projectId;

    public bool $showFormer = false;

    // Department form
    #[Locked]
    public ?int $editingDepartmentId = null;

    public string $department_id = '';

    public string $function = '';

    public ?int $lead_user_id = null;

    // Member form
    #[Locked]
    public ?int $editingMemberId = null;

    public ?int $user_id = null;

    public string $project_role_id = '';

    public string $member_department_id = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->authorize('view', $this->project);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['owner:id,name,username', 'projectManager:id,name,username'])->findOrFail($this->projectId);
    }

    /**
     * @return array{departments: bool, members: bool}
     */
    #[Computed]
    public function can(): array
    {
        $editable = ! $this->project->isArchived();

        return [
            'departments' => $editable && auth()->user()->can('manageDepartments', $this->project),
            'members' => $editable && auth()->user()->can('manageMembers', $this->project),
        ];
    }

    /**
     * @return Collection<int, ProjectDepartment>
     */
    #[Computed]
    public function departments(): Collection
    {
        return $this->project->departments()->with(['department:id,name', 'leadUser:id,name'])->orderByDesc('is_lead')->get();
    }

    /**
     * @return Collection<int, ProjectMember>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->project->memberships()
            ->when(! $this->showFormer, fn ($query) => $query->whereNull('ended_at'))
            ->with(['user:id,name,username,is_active', 'role:id,name', 'department:id,name'])
            ->orderByRaw('ended_at IS NULL DESC')
            ->orderBy('joined_at')
            ->get();
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function availableDepartments(): Collection
    {
        return Department::query()->active()->whereNotIn('id', $this->departments->pluck('department_id'))->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, ProjectRole>
     */
    #[Computed]
    public function roles(): Collection
    {
        return ProjectRole::query()->active()->orderBy('sort_order')->get(['id', 'name', 'description']);
    }

    private function projectDepartment(int $id): ProjectDepartment
    {
        return $this->project->departments()->findOrFail($id);
    }

    private function member(int $id): ProjectMember
    {
        return $this->project->members()->findOrFail($id);
    }

    // Departments --------------------------------------------------------

    public function addDepartment(): void
    {
        $this->authorize('manageDepartments', $this->project);
        $this->reset('editingDepartmentId', 'department_id', 'function', 'lead_user_id');
        $this->resetValidation();
        Flux::modal('department-form')->show();
    }

    public function editDepartment(int $id): void
    {
        $this->authorize('manageDepartments', $this->project);
        $record = $this->projectDepartment($id);
        $this->editingDepartmentId = $record->id;
        $this->department_id = (string) $record->department_id;
        $this->function = (string) $record->function;
        $this->lead_user_id = $record->lead_user_id;
        $this->resetValidation();
        Flux::modal('department-form')->show();
    }

    public function saveDepartment(ManageProjectDepartments $departments): void
    {
        $this->authorize('manageDepartments', $this->project);

        $this->validate([
            'department_id' => [$this->editingDepartmentId ? 'nullable' : 'required', 'integer'],
            'function' => ['nullable', 'string', 'max:150'],
            'lead_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], attributes: ['department_id' => 'Department', 'function' => 'Project function']);

        if ($this->editingDepartmentId) {
            $departments->update($this->projectDepartment($this->editingDepartmentId), $this->function ?: null, $this->lead_user_id);
            $this->notify('success', 'Department project diperbarui.');
        } else {
            $departments->add($this->project, (int) $this->department_id, $this->function ?: null, $this->lead_user_id);
            $this->notify('success', 'Department berhasil ditambahkan ke project.');
        }

        Flux::modal('department-form')->close();
        $this->refreshTeam();
    }

    public function makeLead(int $id, ManageProjectDepartments $departments): void
    {
        $this->authorize('manageDepartments', $this->project);

        $record = $this->projectDepartment($id);
        $departments->changeLead($this->project, $record->department_id);

        $this->notify('success', 'Lead department berhasil diganti.');
        $this->refreshTeam();
    }

    public function removeDepartment(int $id, ManageProjectDepartments $departments): void
    {
        $this->authorize('manageDepartments', $this->project);

        $departments->remove($this->projectDepartment($id));

        $this->notify('success', 'Department dihapus dari project.');
        $this->refreshTeam();
    }

    // Members ------------------------------------------------------------

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->reset('editingMemberId', 'user_id', 'project_role_id', 'member_department_id');
        $this->project_role_id = (string) $this->roles->first()?->id;
        $this->resetValidation();
        Flux::modal('member-form')->show();
    }

    public function editMember(int $id): void
    {
        $this->authorize('manageMembers', $this->project);
        $member = $this->member($id);
        $this->editingMemberId = $member->id;
        $this->project_role_id = (string) $member->project_role_id;
        $this->resetValidation();
        Flux::modal('member-form')->show();
    }

    public function saveMember(ManageProjectMembers $members): void
    {
        $this->authorize('manageMembers', $this->project);

        $this->validate([
            'user_id' => [$this->editingMemberId ? 'nullable' : 'required', 'integer'],
            'project_role_id' => ['required', 'integer'],
            'member_department_id' => ['nullable', 'integer'],
        ], attributes: ['user_id' => 'User', 'project_role_id' => 'Project role', 'member_department_id' => 'Department']);

        if ($this->editingMemberId) {
            $members->changeRole($this->member($this->editingMemberId), (int) $this->project_role_id);
            $this->notify('success', 'Project role berhasil diubah.');
        } else {
            $departmentId = $this->member_department_id !== '' && $this->departments->contains('department_id', (int) $this->member_department_id)
                ? (int) $this->member_department_id
                : null;

            $members->add($this->project, (int) $this->user_id, (int) $this->project_role_id, $departmentId);
            $this->notify('success', 'Anggota berhasil ditambahkan.');
        }

        Flux::modal('member-form')->close();
        $this->refreshTeam();
    }

    public function removeMember(int $id, ManageProjectMembers $members): void
    {
        $this->authorize('manageMembers', $this->project);

        $members->remove($this->member($id));

        $this->notify('success', 'Anggota dikeluarkan dari project. Riwayatnya tetap tersimpan.');
        $this->refreshTeam();
    }

    private function refreshTeam(): void
    {
        unset($this->departments, $this->members, $this->availableDepartments);
        $this->dispatch('project-updated');
    }
}; ?>

<div class="space-y-8">
    {{-- Leadership --}}
    <section>
        <flux:heading size="lg" class="mb-3">Leadership</flux:heading>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (['Project Manager' => $this->project->projectManager, 'Project Owner' => $this->project->owner] as $label => $person)
                <div wire:key="leader-{{ $loop->index }}" class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4">
                    @if ($person)
                        <flux:avatar :name="$person->name" :initials="$person->initials()" />
                        <div class="min-w-0">
                            <div class="truncate font-medium text-zinc-900">{{ $person->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $label }}</div>
                        </div>
                    @else
                        <flux:avatar icon="user" />
                        <div>
                            <div class="font-medium text-zinc-400">Belum ditentukan</div>
                            <div class="text-xs text-zinc-500">{{ $label }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        <flux:text size="sm" class="mt-2">Project Manager dan Owner diubah melalui tombol Edit project.</flux:text>
    </section>

    {{-- Departments --}}
    <section>
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Project Departments</flux:heading>
                <flux:text size="sm">Satu lead department dan supporting department yang berkolaborasi.</flux:text>
            </div>
            @if ($this->can['departments'])
                <flux:button size="sm" icon="plus" wire:click="addDepartment">Add Department</flux:button>
            @endif
        </div>

        <div class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white">
            @foreach ($this->departments as $record)
                <div wire:key="pd-{{ $record->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100">
                            <flux:icon.building-office-2 class="size-4 text-zinc-500" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-zinc-900">{{ $record->department->name }}</span>
                                <flux:badge size="sm" :color="$record->is_lead ? 'indigo' : 'zinc'">{{ $record->is_lead ? 'Lead Department' : 'Supporting' }}</flux:badge>
                            </div>
                            <div class="truncate text-sm text-zinc-500">
                                {{ $record->leadUser ? 'Lead: '.$record->leadUser->name : 'Lead belum ditentukan' }}{{ $record->function ? ' · '.$record->function : '' }}
                            </div>
                        </div>
                    </div>
                    @if ($this->can['departments'])
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions for {{ $record->department->name }}" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="editDepartment({{ $record->id }})">Edit role & lead</flux:menu.item>
                                @unless ($record->is_lead)
                                    <flux:menu.item icon="star"
                                        x-on:click="hwConfirm({ title: 'Jadikan lead department?', text: @js($record->department->name.' akan menjadi lead department. Lead sebelumnya otomatis menjadi supporting department.'), confirmText: 'Jadikan lead' }).then(ok => ok && $wire.makeLead({{ $record->id }}))">Make lead department</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger"
                                        x-on:click="hwConfirm({ title: 'Hapus Department dari Project?', text: @js($record->department->name.' tidak lagi terlibat aktif dalam Project. Riwayat keterlibatan tetap disimpan.'), confirmText: 'Hapus dari project', cancelText: 'Batal', danger: true }).then(ok => ok && $wire.removeDepartment({{ $record->id }}))">Remove from project</flux:menu.item>
                                @endunless
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            @endforeach
            @if ($this->departments->where('is_lead', false)->isEmpty())
                <div class="px-4 py-5 text-center">
                    <flux:text>Belum ada Supporting Department. Tambahkan divisi lain jika project membutuhkan kolaborasi lintas tim.</flux:text>
                </div>
            @endif
        </div>
    </section>

    {{-- Members --}}
    <section>
        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">Project Members</flux:heading>
                <flux:text size="sm">Anggota ditambahkan secara eksplisit dengan peran di project ini.</flux:text>
            </div>
            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="showFormer" label="Show former" />
                @if ($this->can['members'])
                    <flux:button size="sm" icon="user-plus" wire:click="addMember">Add Member</flux:button>
                @endif
            </div>
        </div>

        <x-ui.table-card>
            @if ($this->members->isEmpty())
                <x-ui.empty-state icon="users" title="Belum ada anggota project." description="Tambahkan anggota agar tim tahu siapa saja yang terlibat beserta perannya.">
                    @if ($this->can['members'])
                        <flux:button size="sm" variant="primary" icon="user-plus" wire:click="addMember">Add Member</flux:button>
                    @endif
                </x-ui.empty-state>
            @else
                <flux:table class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5">
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column class="max-md:hidden">Department</flux:table.column>
                        <flux:table.column>Project Role</flux:table.column>
                        <flux:table.column class="max-sm:hidden">Status</flux:table.column>
                        <flux:table.column class="max-lg:hidden">Joined</flux:table.column>
                        <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->members as $member)
                            <flux:table.row :key="$member->id" @class(['opacity-60' => $member->ended_at])>
                                <flux:table.cell>
                                    <div class="flex items-center gap-3">
                                        <flux:avatar size="sm" :name="$member->user->name" :initials="$member->user->initials()" />
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-zinc-900">{{ $member->user->name }}</div>
                                            <div class="truncate text-xs text-zinc-500">{{ '@'.$member->user->username }}</div>
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="max-md:hidden">{{ $member->department->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $member->role->name }}</flux:table.cell>
                                <flux:table.cell class="max-sm:hidden">
                                    @if ($member->ended_at)
                                        <flux:badge size="sm" color="zinc" inset="top bottom">Left {{ $member->ended_at->translatedFormat('d M Y') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="green" inset="top bottom">Active</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="max-lg:hidden">{{ $member->joined_at->translatedFormat('d M Y') }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($this->can['members'] && ! $member->ended_at)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $member->user->name }}" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="editMember({{ $member->id }})">Change role</flux:menu.item>
                                                <flux:menu.item icon="user-minus" variant="danger"
                                                    x-on:click="hwConfirm({ title: 'Keluarkan anggota dari Project?', text: @js($member->user->name.' tidak lagi aktif dalam Project, tetapi riwayat keanggotaannya tetap tersimpan.'), confirmText: 'Keluarkan', cancelText: 'Batal', danger: true }).then(ok => ok && $wire.removeMember({{ $member->id }}))">Remove from project</flux:menu.item>
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
    </section>

    @if ($this->can['departments'])
        <flux:modal name="department-form" class="w-full max-w-md">
            <form wire:submit="saveDepartment" class="space-y-6">
                <flux:heading size="lg">{{ $editingDepartmentId ? 'Edit Project Department' : 'Add Department' }}</flux:heading>
                @unless ($editingDepartmentId)
                    <flux:select wire:model="department_id" label="Department" badge="Required" placeholder="Pilih department">
                        @foreach ($this->availableDepartments as $option)
                            <flux:select.option :value="$option->id" wire:key="ad-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="sm">Department ditambahkan sebagai Supporting. Lead dapat diganti lewat menu "Make lead department".</flux:text>
                @endunless
                <flux:input wire:model="function" label="Project function" placeholder="Contoh: QA & testing, desain UI" maxlength="150" />
                <livewire:user-picker wire:model="lead_user_id" label="Department lead" description="PIC department untuk project ini (opsional)." :key="'dept-lead-'.($editingDepartmentId ?? 'new')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->can['members'])
        <flux:modal name="member-form" class="w-full max-w-md">
            <form wire:submit="saveMember" class="space-y-6">
                <flux:heading size="lg">{{ $editingMemberId ? 'Change Project Role' : 'Add Member' }}</flux:heading>
                @unless ($editingMemberId)
                    <div>
                        <livewire:user-picker wire:model="user_id" label="User" :required="true" :exclude="$this->members->whereNull('ended_at')->pluck('user_id')->all()" key="member-picker" />
                        <flux:error name="user_id" />
                    </div>
                @endunless
                <flux:select wire:model="project_role_id" label="Project role" badge="Required">
                    @foreach ($this->roles as $role)
                        <flux:select.option :value="$role->id" wire:key="role-{{ $role->id }}">{{ $role->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @unless ($editingMemberId)
                    <flux:select wire:model="member_department_id" label="Department context" description="Department tempat anggota berkontribusi di project ini (opsional).">
                        <flux:select.option value="">Tidak spesifik</flux:select.option>
                        @foreach ($this->departments as $record)
                            <flux:select.option :value="$record->department_id" wire:key="md-{{ $record->id }}">{{ $record->department->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endunless
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ $editingMemberId ? 'Save role' : 'Add member' }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
