<?php

use App\Domain\MasterData\MasterRegistry;
use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Master Data')] class extends Component {
    /**
     * Available masters, grouped by domain, limited to what the user may view.
     *
     * @return list<array{heading: string, items: list<array{label: string, description: string, icon: string, url: string, active: int, total: int}>}>
     */
    #[Computed]
    public function groups(): array
    {
        $user = auth()->user();

        $definitions = [
            'Organization' => [
                [Department::class, 'Departments', 'Struktur divisi dan hierarki organisasi.', 'building-office-2', 'admin.departments.index'],
                [Position::class, 'Positions', 'Jabatan untuk keanggotaan department.', 'identification', 'admin.positions.index'],
            ],
            'Client' => [
                [Client::class, 'Clients', 'Referensi client untuk project dan ticket.', 'briefcase', 'clients.index'],
            ],
        ];

        $groups = [];

        foreach ($definitions as $heading => $items) {
            $visible = [];

            foreach ($items as [$model, $label, $description, $icon, $route]) {
                if (! $user->can('viewAny', $model)) {
                    continue;
                }

                $visible[] = [
                    'label' => $label,
                    'description' => $description,
                    'icon' => $icon,
                    'url' => route($route),
                    'active' => $model::query()->active()->count(),
                    'total' => $model::query()->count(),
                ];
            }

            if ($visible !== []) {
                $groups[] = ['heading' => $heading, 'items' => $visible];
            }
        }

        if ($user->can('master-data.view')) {
            $projectItems = [];

            foreach (MasterRegistry::all() as $definition) {
                $query = $definition->model::query()->when($definition->scope !== null, fn ($query) => $query->where('scope', $definition->scope?->value));

                $projectItems[] = [
                    'label' => $definition->label,
                    'description' => $definition->description,
                    'icon' => $definition->icon,
                    'url' => route('master-data.manage', $definition->key),
                    'active' => (clone $query)->where('is_active', true)->count(),
                    'total' => $query->count(),
                ];
            }

            $groups[] = ['heading' => 'Project', 'items' => $projectItems];
        }

        return $groups;
    }

    /**
     * Masters planned for later phases. Shown for orientation only; nothing is clickable.
     *
     * @return list<array{heading: string, phase: string, items: list<string>}>
     */
    public function upcoming(): array
    {
        return [
            ['heading' => 'Task', 'phase' => 'Phase 3', 'items' => ['Task Type', 'Task Status', 'Task Priority']],
            ['heading' => 'Ticket', 'phase' => 'Phase 4', 'items' => ['Ticket Type', 'Ticket Category', 'Ticket Status', 'Ticket Priority', 'Resolution']],
        ];
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="Master Data" description="Kelola data referensi yang dipakai di seluruh HarmaWorkspace. Master yang sudah digunakan dinonaktifkan, bukan dihapus." />

    @forelse ($this->groups as $group)
        <section class="mb-8" wire:key="group-{{ $group['heading'] }}">
            <flux:heading size="sm" class="mb-3 uppercase tracking-wide text-zinc-500!">{{ $group['heading'] }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" wire:navigate wire:key="master-{{ $item['label'] }}"
                       class="group flex flex-col rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300 hover:shadow-xs dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                        <div class="flex items-start justify-between">
                            <div class="flex size-10 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon :icon="$item['icon']" class="size-5 text-zinc-600 dark:text-zinc-300" />
                            </div>
                            <flux:icon.chevron-right class="size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-zinc-500" />
                        </div>
                        <flux:heading class="mt-4">{{ $item['label'] }}</flux:heading>
                        <flux:text class="mt-1">{{ $item['description'] }}</flux:text>
                        <flux:text size="sm" class="mt-4">
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $item['active'] }}</span> aktif
                            <span class="text-zinc-400">· {{ $item['total'] }} total</span>
                        </flux:text>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <x-ui.table-card>
            <x-ui.empty-state icon="circle-stack" title="Tidak ada master data yang dapat Anda akses" description="Hubungi administrator jika Anda membutuhkan akses ke master data tertentu." />
        </x-ui.table-card>
    @endforelse

    <section>
        <flux:heading size="sm" class="mb-3 uppercase tracking-wide text-zinc-500!">Coming soon</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->upcoming() as $group)
                <div wire:key="upcoming-{{ $group['heading'] }}" class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ $group['heading'] }}</flux:heading>
                        <flux:badge size="sm" color="zinc">{{ $group['phase'] }}</flux:badge>
                    </div>
                    <flux:text class="mt-2">{{ implode(' · ', $group['items']) }}</flux:text>
                </div>
            @endforeach
        </div>
    </section>
</div>
