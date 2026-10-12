<?php

use App\Domain\Task\Actions\ChangeTaskDepartment;
use App\Domain\Task\Actions\ChangeTaskEstimate;
use App\Domain\Task\Actions\UpdateTask;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $taskId;

    public string $title = '';

    public string $description = '';

    public string $task_type_id = '';

    public string $task_category_id = '';

    public string $priority_id = '';

    public string $milestone_id = '';

    public string $department_id = '';

    public string $estimated_hours = '';

    public string $weight = '';

    public function mount(Task $task): void
    {
        $this->authorize('update', $task);

        $this->taskId = $task->id;
        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->task_type_id = (string) $task->task_type_id;
        $this->task_category_id = (string) $task->task_category_id;
        $this->priority_id = (string) $task->priority_id;
        $this->milestone_id = (string) $task->milestone_id;
        $this->department_id = (string) $task->department_id;
        $this->estimated_hours = $task->estimated_hours !== null ? rtrim(rtrim((string) $task->estimated_hours, '0'), '.') : '';
        $this->weight = $task->weight !== null ? rtrim(rtrim((string) $task->weight, '0'), '.') : '';
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()->with('project')->findOrFail($this->taskId);
    }

    /**
     * Active options plus the values already stored on the task.
     *
     * @return array{types: Collection<int, TaskType>, categories: Collection<int, TaskCategory>, priorities: Collection<int, Priority>, departments: Collection<int, Department>, milestones: Collection<int, \App\Models\ProjectMilestone>}
     */
    #[Computed]
    public function options(): array
    {
        $task = $this->task;
        $project = $task->project;

        return [
            'types' => TaskType::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $task->task_type_id))->orderBy('sort_order')->get(['id', 'name']),
            'categories' => TaskCategory::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $task->task_category_id))->orderBy('sort_order')->get(['id', 'name']),
            'priorities' => Priority::query()->forScope(MasterScope::Task)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $task->priority_id))->orderBy('level')->get(['id', 'name']),
            'departments' => Department::query()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $task->department_id))
                ->when($project !== null, fn ($query) => $query->whereIn('id', $project->departments()->select('department_id')))
                ->orderBy('name')->get(['id', 'name']),
            'milestones' => $project?->milestones()->orderBy('current_due_date')->get(['id', 'name']) ?? collect(),
        ];
    }

    public function save(UpdateTask $updateTask, ChangeTaskDepartment $changeDepartment, ChangeTaskEstimate $changeEstimate): void
    {
        $task = $this->task;
        $this->authorize('update', $task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'task_type_id' => ['nullable', 'integer'],
            'task_category_id' => ['nullable', 'integer'],
            'priority_id' => ['required', 'integer'],
            'milestone_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'weight' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
        ], attributes: ['title' => 'Task title', 'estimated_hours' => 'Work estimate', 'weight' => 'Weight override']);

        DB::transaction(function () use ($task, $validated, $updateTask, $changeDepartment, $changeEstimate): void {
            $updateTask->handle($task, [
                'title' => $validated['title'],
                'description' => $validated['description'] ?: null,
                'task_type_id' => filled($validated['task_type_id']) ? (int) $validated['task_type_id'] : null,
                'task_category_id' => filled($validated['task_category_id']) ? (int) $validated['task_category_id'] : null,
                'priority_id' => (int) $validated['priority_id'],
                'milestone_id' => filled($validated['milestone_id']) ? (int) $validated['milestone_id'] : null,
            ], auth()->user());

            $changeDepartment->handle($task, filled($validated['department_id']) ? (int) $validated['department_id'] : null, auth()->user());
            $changeEstimate->handle(
                $task,
                filled($validated['estimated_hours']) ? (float) $validated['estimated_hours'] : null,
                filled($validated['weight']) ? (float) $validated['weight'] : null,
                auth()->user(),
            );
        });

        $this->notifyAfterRedirect('success', 'Task berhasil diperbarui.');
        $this->redirectRoute('tasks.show', $task, navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit '.$this->task->number);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="'Edit '.$this->task->number" :description="$this->task->title">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="$this->task->project ? route('projects.show', ['project' => $this->task->project, 'tab' => 'tasks']) : route('tasks.index')" wire:navigate>{{ $this->task->project->name ?? 'Tasks' }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('tasks.show', $this->task)" wire:navigate>{{ $this->task->number }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Edit</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
        <x-ui.form-section title="General" description="Informasi utama task.">
            <flux:input wire:model="title" label="Task title" badge="Required" maxlength="200" />
            <flux:textarea wire:model="description" label="Description" rows="5" />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="priority_id" label="Priority">
                    @foreach ($this->options['priorities'] as $option)
                        <flux:select.option :value="$option->id" wire:key="p-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="task_type_id" label="Type">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->options['types'] as $option)
                        <flux:select.option :value="$option->id" wire:key="t-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="task_category_id" label="Category">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->options['categories'] as $option)
                        <flux:select.option :value="$option->id" wire:key="c-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @if ($this->task->project)
                <flux:select wire:model="milestone_id" label="Milestone">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->options['milestones'] as $option)
                        <flux:select.option :value="$option->id" wire:key="m-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </x-ui.form-section>

        <x-ui.form-section title="Department & Estimate" description="Work estimate menjadi bobot task untuk progress project; weight override hanya bila bobot khusus diperlukan.">
            <flux:select wire:model="department_id" label="Department">
                <flux:select.option value="">Tanpa department</flux:select.option>
                @foreach ($this->options['departments'] as $option)
                    <flux:select.option :value="$option->id" wire:key="d-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="estimated_hours" type="number" step="0.5" min="0" label="Work estimate (hours)" />
                <flux:input wire:model="weight" type="number" step="0.5" min="0" label="Weight override" description="Kosongkan untuk memakai work estimate." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Assignment, Schedule & Progress" description="Diatur dari halaman task agar riwayatnya tercatat.">
            <flux:text>PIC, kolaborator, jadwal (dengan alasan), progress, dan status diubah langsung dari halaman detail task.</flux:text>
        </x-ui.form-section>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" :href="route('tasks.show', $this->task)" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
