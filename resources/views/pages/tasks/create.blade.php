<?php

use App\Domain\Task\Actions\CreateTask;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('New Task')] class extends Component {
    use WithFeedback;

    #[Url(as: 'project')]
    #[Locked]
    public ?int $lockedProjectId = null;

    public string $title = '';

    public string $project_id = '';

    public string $department_id = '';

    public ?int $pic_user_id = null;

    public string $status_id = '';

    public string $priority_id = '';

    public string $due_date = '';

    public bool $showMore = false;

    public string $description = '';

    public string $task_type_id = '';

    public string $task_category_id = '';

    public string $milestone_id = '';

    public string $start_date = '';

    public string $estimated_hours = '';

    public string $weight = '';

    /** @var list<int> */
    public array $collaborator_ids = [];

    public ?int $collaboratorToAdd = null;

    public function mount(): void
    {
        if ($this->lockedProjectId !== null) {
            $project = Project::query()->with('status')->findOrFail($this->lockedProjectId);
            abort_if($project->isArchived() || $project->status->is_terminal, 403, 'Project ini tidak lagi menerima task baru.');
            $this->authorize('create', [Task::class, $project]);
            $this->project_id = (string) $project->id;
            $this->applyProjectDefaults();
        } else {
            $this->authorize('create', Task::class);
            $this->department_id = (string) auth()->user()->primaryMembership()->value('department_id');
            $this->pic_user_id = auth()->id();
        }

        $this->status_id = (string) Status::query()->forScope(MasterScope::Task)->active()->where('is_initial', true)->value('id');
        $this->priority_id = (string) Priority::query()->forScope(MasterScope::Task)->active()->orderBy('level')->skip(1)->value('id');
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->project_id !== '' ? Project::query()->with('status')->find((int) $this->project_id) : null;
    }

    /**
     * Projects the user may add tasks to (shown when no project is fixed).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->visibleTo(auth()->user())->open()->orderBy('name')->get(['id', 'number', 'name', 'project_manager_id', 'status_id', 'archived_at'])
            ->filter(fn (Project $project): bool => auth()->user()->can('create', [Task::class, $project]))
            ->values();
    }

    /**
     * Departments: those involved in the project, or every active department.
     *
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departmentOptions(): Collection
    {
        $project = $this->project;

        return Department::query()
            ->active()
            ->when($project !== null, fn ($query) => $query->whereIn('id', $project->departments()->select('department_id')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Users that may be assigned: project members + PM, or anyone active.
     *
     * @return list<int>|null
     */
    #[Computed]
    public function assignableIds(): ?array
    {
        $project = $this->project;

        if ($project === null) {
            return null;
        }

        return array_values(array_unique([$project->project_manager_id, ...array_map(intval(...), $project->members()->pluck('user_id')->all())]));
    }

    /**
     * @return array{statuses: Collection<int, Status>, priorities: Collection<int, Priority>, types: Collection<int, TaskType>, categories: Collection<int, TaskCategory>, milestones: Collection<int, \App\Models\ProjectMilestone>}
     */
    #[Computed]
    public function options(): array
    {
        return [
            'statuses' => Status::query()->forScope(MasterScope::Task)->active()->where('is_terminal', false)->where('is_blocked', false)->where('counts_as_started', false)->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Task)->active()->orderBy('level')->get(['id', 'name']),
            'types' => TaskType::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'categories' => TaskCategory::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'milestones' => $this->project?->milestones()->whereNull('actual_date')->orderBy('current_due_date')->get(['id', 'name']) ?? collect(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function collaborators(): Collection
    {
        return User::query()->whereIn('id', $this->collaborator_ids)->orderBy('name')->get(['id', 'name']);
    }

    public function updatedProjectId(): void
    {
        abort_if($this->lockedProjectId !== null, 403);

        $this->reset('department_id', 'milestone_id', 'collaborator_ids');
        unset($this->project, $this->assignableIds, $this->departmentOptions);
        $this->pic_user_id = $this->project && ! in_array(auth()->id(), $this->assignableIds ?? [], true) ? null : auth()->id();

        if ($this->project !== null) {
            $this->applyProjectDefaults();
        }
    }

    /**
     * Quick create from a project: the user's own department when it takes
     * part in the project (otherwise the lead department), and the user as
     * PIC when they may be assigned. Both stay editable and validated.
     */
    private function applyProjectDefaults(): void
    {
        $project = $this->project;
        $departmentIds = array_map(intval(...), $project->departments()->pluck('department_id')->all());
        $mine = auth()->user()->activeMemberships()->whereIn('department_id', $departmentIds)->orderByDesc('is_primary')->value('department_id');

        $this->department_id = (string) ($mine ?? $project->leadDepartment()->value('department_id') ?? '');

        if (in_array(auth()->id(), $this->assignableIds ?? [], true)) {
            $this->pic_user_id = auth()->id();
        }
    }

    public function updatedCollaboratorToAdd(?int $value): void
    {
        if ($value !== null && $value !== $this->pic_user_id && ! in_array($value, $this->collaborator_ids, true)) {
            $this->collaborator_ids[] = $value;
        }

        $this->collaboratorToAdd = null;
    }

    public function removeCollaborator(int $userId): void
    {
        $this->collaborator_ids = array_values(array_diff($this->collaborator_ids, [$userId]));
    }

    public function save(CreateTask $createTask): void
    {
        $project = $this->project;
        $this->authorize('create', [Task::class, $project]);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'project_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer', 'required_without:pic_user_id'],
            'pic_user_id' => ['nullable', 'integer', 'required_without:department_id'],
            'status_id' => ['required', 'integer'],
            'priority_id' => ['required', 'integer'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date', 'before_or_equal:due_date'],
            'description' => ['nullable', 'string', 'max:10000'],
            'task_type_id' => ['nullable', 'integer'],
            'task_category_id' => ['nullable', 'integer'],
            'milestone_id' => ['nullable', 'integer'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'weight' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'collaborator_ids' => ['array'],
            'collaborator_ids.*' => ['integer', 'distinct'],
        ], [
            'department_id.required_without' => 'Pilih Department atau PIC agar jelas siapa yang bertanggung jawab.',
            'pic_user_id.required_without' => 'Pilih PIC atau Department agar jelas siapa yang bertanggung jawab.',
            'start_date.before_or_equal' => 'Tanggal mulai tidak boleh setelah deadline.',
        ], [
            'title' => 'Task title', 'due_date' => 'Deadline', 'start_date' => 'Start date', 'estimated_hours' => 'Work estimate', 'weight' => 'Weight override',
        ]);

        $task = $createTask->handle([
            ...$validated,
            'project_id' => $project?->id,
            'department_id' => filled($validated['department_id']) ? (int) $validated['department_id'] : null,
            'milestone_id' => filled($validated['milestone_id']) ? (int) $validated['milestone_id'] : null,
            'task_type_id' => filled($validated['task_type_id']) ? (int) $validated['task_type_id'] : null,
            'task_category_id' => filled($validated['task_category_id']) ? (int) $validated['task_category_id'] : null,
            'status_id' => (int) $validated['status_id'],
            'priority_id' => (int) $validated['priority_id'],
            'collaborator_ids' => array_map(intval(...), $validated['collaborator_ids']),
            'estimated_hours' => filled($validated['estimated_hours']) ? (float) $validated['estimated_hours'] : null,
            'weight' => filled($validated['weight']) ? (float) $validated['weight'] : null,
        ], auth()->user());

        $this->notifyAfterRedirect('success', 'Task '.$task->number.' berhasil dibuat.');
        $this->redirectRoute('tasks.show', $task, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-3xl">
    <x-ui.page-header title="New Task" description="Isi yang penting dulu. Detail lain dapat ditambahkan lewat More options atau setelah task dibuat.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                @if ($this->project && $lockedProjectId)
                    <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item :href="route('projects.show', ['project' => $this->project, 'tab' => 'tasks'])" wire:navigate>{{ $this->project->name }}</flux:breadcrumbs.item>
                @else
                    <flux:breadcrumbs.item :href="route('tasks.index')" wire:navigate>Tasks</flux:breadcrumbs.item>
                @endif
                <flux:breadcrumbs.item>New</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
        <flux:input wire:model="title" label="Task title" badge="Required" maxlength="200" placeholder="Contoh: API Penjualan" autofocus />

        @if ($lockedProjectId)
            <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm">
                <span class="text-zinc-500">Project</span>
                <div class="font-medium text-zinc-900">{{ $this->project->number }} · {{ $this->project->name }}</div>
            </div>
        @else
            <flux:select wire:model.live="project_id" label="Project" description="Kosongkan untuk task operasional (standalone).">
                <flux:select.option value="">Standalone task (tanpa project)</flux:select.option>
                @foreach ($this->projectOptions as $option)
                    <flux:select.option :value="$option->id" wire:key="pr-{{ $option->id }}">{{ $option->number }} · {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="department_id" label="Department" :description="$this->project ? 'Hanya department yang terlibat di project.' : null" placeholder="Pilih department">
                <flux:select.option value="">Tanpa department</flux:select.option>
                @foreach ($this->departmentOptions as $option)
                    <flux:select.option :value="$option->id" wire:key="dep-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div>
                <livewire:user-picker wire:model="pic_user_id" label="Main PIC" :only="$this->assignableIds"
                    :description="$this->project ? 'Anggota aktif project atau Project Manager.' : null"
                    empty-hint="Belum ada anggota project yang cocok. Tambahkan anggota di tab Team project."
                    :key="'pic-'.$project_id" />
                <flux:error name="pic_user_id" />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="priority_id" label="Priority">
                @foreach ($this->options['priorities'] as $option)
                    <flux:select.option :value="$option->id" wire:key="pp-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="due_date" type="date" label="Deadline" />
        </div>

        <div class="border-t border-zinc-100 pt-4">
            <flux:button variant="ghost" size="sm" :icon="$showMore ? 'chevron-up' : 'chevron-down'" wire:click="$toggle('showMore')">More options</flux:button>
        </div>

        @if ($showMore)
            <div class="space-y-6">
                <flux:textarea wire:model="description" label="Description" rows="4" />
                @if ($this->options['statuses']->count() > 1)
                    <flux:select wire:model="status_id" label="Initial status" description="Biasanya cukup status awal default.">
                        @foreach ($this->options['statuses'] as $option)
                            <flux:select.option :value="$option->id" wire:key="st-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="task_type_id" label="Type">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->options['types'] as $option)
                            <flux:select.option :value="$option->id" wire:key="ty-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="task_category_id" label="Category">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->options['categories'] as $option)
                            <flux:select.option :value="$option->id" wire:key="ca-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($this->project)
                    <flux:select wire:model="milestone_id" label="Milestone">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->options['milestones'] as $option)
                            <flux:select.option :value="$option->id" wire:key="ms-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <div>
                    <livewire:user-picker wire:model.live="collaboratorToAdd" label="Collaborators" :only="$this->assignableIds" :exclude="array_values(array_filter([$pic_user_id, ...$collaborator_ids]))" placeholder="Tambah kolaborator..." :key="'collab-'.$project_id.'-'.count($collaborator_ids)" />
                    @if ($this->collaborators->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($this->collaborators as $collaborator)
                                <flux:badge wire:key="cb-{{ $collaborator->id }}" size="sm" color="zinc">
                                    {{ $collaborator->name }}
                                    <flux:badge.close wire:click="removeCollaborator({{ $collaborator->id }})" aria-label="Remove {{ $collaborator->name }}" />
                                </flux:badge>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="start_date" type="date" label="Start date" />
                    <flux:input wire:model="estimated_hours" type="number" step="0.5" min="0" label="Work estimate (hours)" description="Opsional, untuk perencanaan." />
                    <flux:input wire:model="weight" type="number" step="0.5" min="0" label="Weight override" description="Jarang diperlukan." />
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="$lockedProjectId ? route('projects.show', ['project' => $lockedProjectId, 'tab' => 'tasks']) : route('tasks.index')" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="save">Create Task</span>
                <span wire:loading wire:target="save">Creating...</span>
            </flux:button>
        </div>
    </form>
</div>
