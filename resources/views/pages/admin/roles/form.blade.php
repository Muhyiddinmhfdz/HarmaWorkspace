<?php

use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\UpdateRole;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\Rules\RoleRules;
use App\Livewire\Concerns\WithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public ?int $roleId = null;

    public string $name = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('update', $role);

            if ($role->name === config('harma.super_admin_role')) {
                abort(403, 'Role Super Admin dikelola oleh sistem.');
            }

            $this->roleId = $role->id;
            $this->name = $role->name;
            $this->permissions = $role->permissions()->pluck('name')->all();

            return;
        }

        $this->authorize('create', Role::class);
    }

    #[Computed]
    public function editing(): ?Role
    {
        return $this->roleId ? Role::findOrFail($this->roleId) : null;
    }

    /**
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    #[Computed]
    public function groups(): array
    {
        return PermissionRegistry::groups();
    }

    /**
     * Permissions the current user may not grant (non-super-admins can only
     * grant what they hold).
     *
     * @return list<string>
     */
    #[Computed]
    public function lockedPermissions(): array
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return [];
        }

        return array_values(array_filter(PermissionRegistry::all(), fn (string $permission): bool => ! $user->can($permission)));
    }

    public function toggleGroup(string $group): void
    {
        $groupPermissions = array_diff(array_keys($this->groups[$group]['permissions'] ?? []), $this->lockedPermissions);
        $allSelected = array_diff($groupPermissions, $this->permissions) === [];

        $this->permissions = $allSelected
            ? array_values(array_diff($this->permissions, $groupPermissions))
            : array_values(array_unique([...$this->permissions, ...$groupPermissions]));
    }

    public function save(CreateRole $createRole, UpdateRole $updateRole): void
    {
        $role = $this->editing;
        $role ? $this->authorize('update', $role) : $this->authorize('create', Role::class);

        $validated = $this->validate(RoleRules::rules($role));

        if ($role) {
            $updateRole->handle($role, $validated, auth()->user());
            $this->notifyAfterRedirect('success', 'Role berhasil diperbarui.');
        } else {
            $createRole->handle($validated, auth()->user());
            $this->notifyAfterRedirect('success', 'Role berhasil ditambahkan.');
        }

        $this->redirectRoute('admin.roles.index', navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->roleId ? 'Edit Role' : 'Add Role');
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="$roleId ? 'Edit Role · '.$name : 'Add Role'" description="Pilih permission yang dimiliki role ini. Perubahan berlaku untuk semua user dengan role tersebut dan tercatat di audit log.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.roles.index')" wire:navigate>Role & Permission</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $roleId ? 'Edit' : 'Add' }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <x-ui.form-section title="Role" description="Nama role tampil pada daftar user.">
            <flux:input wire:model="name" label="Role name" badge="Required" maxlength="60" />
        </x-ui.form-section>

        <x-ui.form-section title="Permissions" description="Permission menentukan apa yang boleh dilakukan. Aturan tambahan (misal: tidak dapat menonaktifkan diri sendiri) tetap berlaku.">
            @error('permissions')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror
            @error('permissions.*')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror

            <div class="space-y-4">
                @foreach ($this->groups as $groupKey => $group)
                    <div wire:key="group-{{ $groupKey }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-700">
                            <flux:heading>{{ $group['label'] }}</flux:heading>
                            <flux:button size="xs" variant="ghost" wire:click="toggleGroup('{{ $groupKey }}')">Toggle all</flux:button>
                        </div>
                        <div class="grid gap-3 p-4 sm:grid-cols-2">
                            @foreach ($group['permissions'] as $permission => $description)
                                <flux:checkbox
                                    wire:model="permissions"
                                    :value="$permission"
                                    :label="$permission"
                                    :description="$description"
                                    :disabled="in_array($permission, $this->lockedPermissions, true)"
                                    wire:key="permission-{{ $permission }}"
                                />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($this->lockedPermissions !== [])
                <flux:text size="sm">Permission yang tidak aktif tidak dapat Anda berikan karena Anda sendiri tidak memilikinya.</flux:text>
            @endif
        </x-ui.form-section>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" :href="route('admin.roles.index')" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="save">{{ $roleId ? 'Save changes' : 'Create role' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
