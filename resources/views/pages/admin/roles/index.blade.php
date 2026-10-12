<?php

use App\Domain\Access\Actions\DeleteRole;
use App\Domain\Access\PermissionRegistry;
use App\Livewire\Concerns\WithFeedback;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Role & Permission')] class extends Component {
    use WithFeedback;

    #[Locked]
    public ?int $deleteTargetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function deleteTarget(): ?Role
    {
        return $this->deleteTargetId ? Role::find($this->deleteTargetId) : null;
    }

    public function isSystemRole(Role $role): bool
    {
        return $role->name === config('harma.super_admin_role')
            || array_key_exists($role->name, PermissionRegistry::defaultRoles());
    }

    public function confirmDelete(int $id): void
    {
        $role = Role::findOrFail($id);
        $this->authorize('delete', $role);

        $this->deleteTargetId = $role->id;
        Flux::modal('role-delete')->show();
    }

    public function delete(DeleteRole $deleteRole): void
    {
        $role = Role::findOrFail($this->deleteTargetId);
        $this->authorize('delete', $role);

        $deleteRole->handle($role);

        $this->deleteTargetId = null;
        unset($this->roles);
        Flux::modal('role-delete')->close();
        $this->notify('success', 'Role berhasil dihapus.');
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Role & Permission" description="Role sistem menentukan permission user. Peran kontekstual seperti Project Manager dan Department Leader tidak diatur di sini.">
        <x-slot:actions>
            @can('create', Spatie\Permission\Models\Role::class)
                <flux:button variant="primary" icon="plus" :href="route('admin.roles.create')" wire:navigate>Add Role</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.access-tabs')

    <x-ui.table-card>
        <flux:table class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5">
            <flux:table.columns>
                <flux:table.column>Role</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Permissions</flux:table.column>
                <flux:table.column>Users</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">Actions</span></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->roles as $role)
                    @php($isSuperAdmin = $role->name === config('harma.super_admin_role'))
                    <flux:table.row :key="$role->id">
                        <flux:table.cell variant="strong">
                            @can('update', $role)
                                <a href="{{ route('admin.roles.edit', $role) }}" wire:navigate class="hover:underline">{{ $role->name }}</a>
                            @else
                                {{ $role->name }}
                            @endcan
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($this->isSystemRole($role))
                                <flux:badge size="sm" color="blue" inset="top bottom">System</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" inset="top bottom">Custom</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $isSuperAdmin ? 'Semua permission' : $role->permissions_count }}</flux:table.cell>
                        <flux:table.cell>{{ $role->users_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if (! $isSuperAdmin)
                                @canany(['update', 'delete'], $role)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" aria-label="Actions for {{ $role->name }}" />
                                        <flux:menu>
                                            @can('update', $role)
                                                <flux:menu.item icon="pencil-square" :href="route('admin.roles.edit', $role)" wire:navigate>Edit permissions</flux:menu.item>
                                            @endcan
                                            @can('delete', $role)
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $role->id }})" :disabled="$role->users_count > 0">Delete</flux:menu.item>
                                            @endcan
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcanany
                            @else
                                <flux:tooltip content="Role Super Admin dikelola sistem dan selalu memiliki semua permission.">
                                    <flux:icon.lock-closed class="ms-auto size-4 text-zinc-400" />
                                </flux:tooltip>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-ui.table-card>

    <flux:modal name="role-delete" class="w-full max-w-md">
        @if ($this->deleteTarget)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Hapus role {{ $this->deleteTarget->name }}?</flux:heading>
                    <flux:text class="mt-2">Role hanya dapat dihapus jika tidak digunakan oleh user mana pun. Penghapusan tercatat di audit log.</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled">Delete</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
