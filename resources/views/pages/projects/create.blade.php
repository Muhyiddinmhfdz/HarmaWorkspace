<?php

use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Rules\ProjectRules;
use App\Domain\Project\Support\VisibilityGuard;
use App\Enums\MasterScope;
use App\Enums\ProjectVisibility;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New Project')] class extends Component {
    use WithFeedback;

    public const array STEPS = [1 => 'Basic Information', 2 => 'Ownership & Team', 3 => 'Schedule', 4 => 'Review'];

    public int $step = 1;

    // Step 1
    public string $name = '';

    public string $client_id = '';

    public string $project_category_id = '';

    public string $priority_id = '';

    public string $description = '';

    public string $visibility = 'restricted';

    // Step 2
    public ?int $owner_id = null;

    public ?int $project_manager_id = null;

    public string $lead_department_id = '';

    public ?int $lead_user_id = null;

    /** @var list<string> */
    public array $supporting_department_ids = [];

    // Step 3
    public string $start_date = '';

    public string $end_date = '';

    public function mount(): void
    {
        $this->authorize('create', Project::class);

        $this->project_manager_id = auth()->id();
        $this->priority_id = (string) Priority::query()->forScope(MasterScope::Project)->active()->orderBy('level')->skip(1)->value('id');
        $this->start_date = today()->toDateString();
        $this->end_date = today()->addMonths(2)->toDateString();
    }

    /**
     * @return array{clients: Collection<int, Client>, categories: Collection<int, ProjectCategory>, priorities: Collection<int, Priority>, departments: Collection<int, Department>}
     */
    #[Computed]
    public function options(): array
    {
        return [
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'name']),
            'categories' => ProjectCategory::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Project)->active()->orderBy('level')->get(['id', 'name', 'color']),
            'departments' => Department::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [...ProjectRules::general(), ...ProjectRules::visibility()],
            2 => [...ProjectRules::ownership(), ...ProjectRules::departments()],
            3 => ProjectRules::schedule(),
            default => [],
        };
    }

    public function canSetVisibility(ProjectVisibility $visibility): bool
    {
        return app(VisibilityGuard::class)->canSet($visibility, auth()->user());
    }

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));

        $this->step = min(4, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    public function updatedLeadDepartmentId(): void
    {
        $this->supporting_department_ids = array_values(array_diff($this->supporting_department_ids, [$this->lead_department_id]));
    }

    /**
     * @return array<string, string|null>
     */
    #[Computed]
    public function summary(): array
    {
        $users = User::query()->whereIn('id', array_filter([$this->owner_id, $this->project_manager_id, $this->lead_user_id]))->pluck('name', 'id');
        $departments = $this->options['departments']->pluck('name', 'id');

        return [
            'Project' => $this->name,
            'Client' => $this->options['clients']->firstWhere('id', (int) $this->client_id)?->name ?? 'Internal Project',
            'Category' => $this->options['categories']->firstWhere('id', (int) $this->project_category_id)?->name,
            'Priority' => $this->options['priorities']->firstWhere('id', (int) $this->priority_id)?->name,
            'Project Owner' => $this->owner_id ? $users[$this->owner_id] ?? null : 'Belum ditentukan',
            'Project Manager' => $users[$this->project_manager_id] ?? null,
            'Lead Department' => ($departments[(int) $this->lead_department_id] ?? '—').($this->lead_user_id ? ' (lead: '.($users[$this->lead_user_id] ?? '').')' : ''),
            'Supporting Departments' => collect($this->supporting_department_ids)->map(fn ($id) => $departments[(int) $id] ?? null)->filter()->join(', ') ?: 'Tidak ada',
            'Visibility' => ProjectVisibility::from($this->visibility)->label(),
            'Schedule' => filled($this->start_date) && filled($this->end_date)
                ? Carbon::parse($this->start_date)->translatedFormat('d M Y').' → '.Carbon::parse($this->end_date)->translatedFormat('d M Y')
                : null,
        ];
    }

    public function create(CreateProject $createProject): void
    {
        $this->authorize('create', Project::class);

        foreach ([1, 2, 3] as $step) {
            try {
                $this->validate($this->rulesForStep($step));
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->step = $step;

                throw $e;
            }
        }

        $project = $createProject->handle([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'client_id' => $this->client_id !== '' ? (int) $this->client_id : null,
            'project_category_id' => (int) $this->project_category_id,
            'priority_id' => (int) $this->priority_id,
            'visibility' => $this->visibility,
            'owner_id' => $this->owner_id,
            'project_manager_id' => (int) $this->project_manager_id,
            'lead_department_id' => (int) $this->lead_department_id,
            'lead_user_id' => $this->lead_user_id,
            'supporting_department_ids' => array_map(intval(...), $this->supporting_department_ids),
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ], auth()->user());

        $this->notifyAfterRedirect('success', 'Project '.$project->number.' berhasil dibuat.');
        $this->redirectRoute('projects.show', $project, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-3xl">
    <x-ui.page-header title="New Project" description="Buat project dalam empat langkah singkat. Anggota dan milestone dapat ditambahkan setelah project dibuat.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>New</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    {{-- Stepper --}}
    <ol class="mb-6 grid grid-cols-4 gap-2">
        @foreach ($this::STEPS as $number => $label)
            <li wire:key="step-{{ $number }}">
                <button type="button" wire:click="goTo({{ $number }})" @disabled($number >= $step) class="w-full text-start">
                    <div @class(['h-1 rounded-full', 'bg-accent' => $number <= $step, 'bg-zinc-200' => $number > $step])></div>
                    <div @class(['mt-2 text-xs font-medium', 'text-zinc-900' => $number === $step, 'text-zinc-500' => $number !== $step])>
                        <span class="tabular-nums">{{ $number }}.</span> <span class="max-sm:hidden">{{ $label }}</span>
                    </div>
                </button>
            </li>
        @endforeach
    </ol>

    <form wire:submit="{{ $step < 4 ? 'next' : 'create' }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
        <flux:heading size="lg" class="mb-1">{{ $this::STEPS[$step] }}</flux:heading>

        @if ($step === 1)
            <flux:text class="mb-6">Identitas project. Client boleh dikosongkan untuk project internal.</flux:text>
            <div class="space-y-6">
                <flux:input wire:model="name" label="Project name" badge="Required" maxlength="150" placeholder="Contoh: POS Development" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="client_id" label="Client" description="Kosongkan untuk Internal Project.">
                        <flux:select.option value="">Internal Project</flux:select.option>
                        @foreach ($this->options['clients'] as $client)
                            <flux:select.option :value="$client->id" wire:key="client-{{ $client->id }}">{{ $client->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="project_category_id" label="Category" badge="Required" placeholder="Pilih category">
                        @foreach ($this->options['categories'] as $category)
                            <flux:select.option :value="$category->id" wire:key="category-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($this->options['categories']->isEmpty())
                    <flux:callout variant="warning" icon="exclamation-triangle" heading="Belum ada Project Category aktif." text="Tambahkan category di Master Data terlebih dahulu." />
                @endif
                <flux:radio.group wire:model="priority_id" label="Priority" variant="segmented">
                    @foreach ($this->options['priorities'] as $priority)
                        <flux:radio :value="(string) $priority->id" :label="$priority->name" wire:key="priority-{{ $priority->id }}" />
                    @endforeach
                </flux:radio.group>
                <flux:textarea wire:model="description" label="Description" rows="4" placeholder="Tujuan dan ruang lingkup project secara singkat." />
                <flux:radio.group wire:model="visibility" label="Project Visibility" description="Siapa yang dapat melihat project ini. Project tanpa client tetap Restricted kecuali Anda memilih lain.">
                    @foreach (\App\Enums\ProjectVisibility::cases() as $option)
                        <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description().($this->canSetVisibility($option) ? '' : ' (butuh izin administrator)')" :disabled="! $this->canSetVisibility($option)" wire:key="vis-{{ $option->value }}" />
                    @endforeach
                </flux:radio.group>
            </div>
        @elseif ($step === 2)
            <flux:text class="mb-6">Siapa yang bertanggung jawab dan divisi mana yang terlibat.</flux:text>
            <div class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <livewire:user-picker wire:model="project_manager_id" label="Project Manager" :required="true" description="Mengelola project sehari-hari." wire:key="picker-pm" />
                        <flux:error name="project_manager_id" />
                    </div>
                    <div>
                        <livewire:user-picker wire:model="owner_id" label="Project Owner" description="Penanggung jawab bisnis (opsional)." wire:key="picker-owner" />
                        <flux:error name="owner_id" />
                    </div>
                </div>

                <flux:separator variant="subtle" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="lead_department_id" label="Lead Department" badge="Required" description="Divisi utama yang bertanggung jawab." placeholder="Pilih department">
                        @foreach ($this->options['departments'] as $department)
                            <flux:select.option :value="$department->id" wire:key="lead-{{ $department->id }}">{{ $department->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div>
                        <livewire:user-picker wire:model="lead_user_id" label="Department lead" description="PIC dari lead department (opsional)." wire:key="picker-lead" />
                    </div>
                </div>

                <flux:field>
                    <flux:label>Supporting Departments</flux:label>
                    <flux:description>Divisi lain yang ikut berkolaborasi. Dapat diubah nanti di tab Team.</flux:description>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($this->options['departments'] as $department)
                            @if ((string) $department->id !== $lead_department_id)
                                <flux:checkbox wire:model="supporting_department_ids" :value="(string) $department->id" :label="$department->name" wire:key="support-{{ $department->id }}" />
                            @endif
                        @endforeach
                    </div>
                    <flux:error name="supporting_department_ids.*" />
                </flux:field>

            </div>
        @elseif ($step === 3)
            <flux:text class="mb-6">Jadwal awal menjadi baseline (rencana awal) project. Perubahan berikutnya tercatat beserta alasannya.</flux:text>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="start_date" type="date" label="Start date" badge="Required" />
                <flux:input wire:model="end_date" type="date" label="Target deadline" badge="Required" />
            </div>
        @else
            <flux:text class="mb-6">Periksa kembali sebelum membuat project. Nomor project dibuat otomatis.</flux:text>
            <dl class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 px-4">
                @foreach ($this->summary as $label => $value)
                    <x-ui.detail-item :label="$label" wire:key="summary-{{ $loop->index }}">{{ $value }}</x-ui.detail-item>
                @endforeach
            </dl>
        @endif

        <div class="mt-8 flex items-center justify-between gap-2">
            @if ($step > 1)
                <flux:button variant="ghost" icon="arrow-left" wire:click="back">Back</flux:button>
            @else
                <flux:button variant="ghost" :href="route('projects.index')" wire:navigate>Cancel</flux:button>
            @endif

            <flux:button type="submit" variant="primary" :icon:trailing="$step < 4 ? 'arrow-right' : null" data-test="wizard-submit">
                <span wire:loading.remove wire:target="next,create">{{ $step < 4 ? 'Continue' : 'Create Project' }}</span>
                <span wire:loading wire:target="next,create">{{ $step < 4 ? 'Checking...' : 'Creating...' }}</span>
            </flux:button>
        </div>
    </form>
</div>
