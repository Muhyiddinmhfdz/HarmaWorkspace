<?php

use App\Domain\Access\PermissionRegistry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

new #[Title('Permissions')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()->can('permission.view'), 403);
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
     * Role names per permission.
     *
     * @return Collection<string, list<string>>
     */
    #[Computed]
    public function rolesByPermission(): Collection
    {
        return Permission::query()
            ->with('roles:id,name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Permission $permission): array => [
                $permission->name => $permission->roles->pluck('name')->sort()->values()->all(),
            ]);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Role & Permission" description="Katalog permission didefinisikan oleh sistem. Atur permission melalui role." />

    @include('partials.access-tabs')

    <div class="space-y-6">
        @foreach ($this->groups as $groupKey => $group)
            <x-ui.table-card wire:key="group-{{ $groupKey }}">
                <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    <flux:heading>{{ $group['label'] }}</flux:heading>
                </div>
                <flux:table class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5">
                    <flux:table.columns>
                        <flux:table.column class="w-1/4">Permission</flux:table.column>
                        <flux:table.column>Description</flux:table.column>
                        <flux:table.column class="max-md:hidden">Roles</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($group['permissions'] as $permission => $description)
                            <flux:table.row :key="$permission">
                                <flux:table.cell class="font-mono text-xs">{{ $permission }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">{{ $description }}</flux:table.cell>
                                <flux:table.cell class="max-md:hidden">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($this->rolesByPermission[$permission] ?? [] as $roleName)
                                            <flux:badge size="sm" color="zinc">{{ $roleName }}</flux:badge>
                                        @endforeach
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ui.table-card>
        @endforeach
    </div>
</div>
