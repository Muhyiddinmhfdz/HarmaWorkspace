<?php

use App\Domain\Ticket\Actions\CreateTicket;
use App\Domain\Ticket\Rules\AttachmentRules;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSource;
use App\Models\TicketSubcategory;
use App\Models\TicketType;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('New Report')] class extends Component {
    use WithFeedback, WithFileUploads;

    #[Url(as: 'project')]
    #[Locked]
    public ?int $lockedProjectId = null;

    #[Url(as: 'task')]
    #[Locked]
    public ?int $lockedTaskId = null;

    public string $title = '';

    public string $ticket_type_id = '';

    public string $ticket_category_id = '';

    public string $priority_id = '';

    public ?int $current_department_id = null;

    public string $description = '';

    public bool $showMore = false;

    public string $client_id = '';

    public string $project_id = '';

    public string $task_id = '';

    public string $ticket_subcategory_id = '';

    public string $ticket_source_id = '';

    public string $due_date = '';

    public string $workflow_id = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public function mount(): void
    {
        $this->authorize('create', Ticket::class);

        if ($this->lockedTaskId !== null) {
            $task = Task::query()->with('project.status')->findOrFail($this->lockedTaskId);
            $this->authorize('view', $task);
            $this->task_id = (string) $task->id;
            $this->lockedProjectId = $task->project_id;
        }

        if ($this->lockedProjectId !== null) {
            $project = Project::query()->with('status')->findOrFail($this->lockedProjectId);
            $this->authorize('view', $project);
            abort_if($project->isArchived() || $project->status->is_terminal, 403, 'Project ini tidak lagi menerima Report baru.');
            $this->project_id = (string) $project->id;
            $this->client_id = (string) $project->client_id;
        }

        $this->priority_id = (string) Priority::query()->forScope(MasterScope::Ticket)->active()->orderBy('level')->skip(1)->value('id');

        // One category means no real choice: preselect it.
        if ($this->options['categories']->count() === 1) {
            $this->ticket_category_id = (string) $this->options['categories']->first()->id;
        }
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->project_id !== '' ? Project::query()->with('status')->find((int) $this->project_id) : null;
    }

    #[Computed]
    public function lockedTask(): ?Task
    {
        return $this->lockedTaskId ? Task::query()->find($this->lockedTaskId, ['id', 'number', 'title']) : null;
    }

    /**
     * @return array{types: Collection<int, TicketType>, categories: Collection<int, TicketCategory>, subcategories: Collection<int, TicketSubcategory>, priorities: Collection<int, Priority>, sources: Collection<int, TicketSource>, clients: Collection<int, Client>}
     */
    #[Computed]
    public function options(): array
    {
        return [
            'types' => TicketType::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'description']),
            'categories' => TicketCategory::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'subcategories' => $this->ticket_category_id !== ''
                ? TicketSubcategory::query()->active()->where('ticket_category_id', (int) $this->ticket_category_id)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'priorities' => Priority::query()->forScope(MasterScope::Ticket)->active()->orderBy('level')->get(['id', 'name']),
            'sources' => TicketSource::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Usable ticket processes; the selector only appears when there is a
     * real choice (the default suits most tickets).
     *
     * @return Collection<int, \App\Models\Workflow>
     */
    #[Computed]
    public function processes(): Collection
    {
        return \App\Models\Workflow::query()->forEntity(\App\Enums\WorkflowEntity::Ticket)->usable()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
    }

    /**
     * Open projects the user can see (archived/finished projects take no new tickets).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->visibleTo(auth()->user())->open()->orderBy('name')->get(['id', 'number', 'name', 'client_id']);
    }

    /**
     * Visible tasks: of the selected project, or the most recent ones.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function taskOptions(): Collection
    {
        return Task::query()
            ->visibleTo(auth()->user())
            ->when($this->project_id !== '', fn ($query) => $query->where('project_id', (int) $this->project_id))
            ->latest('id')
            ->limit(100)
            ->get(['id', 'number', 'title', 'project_id']);
    }

    /**
     * Ticket creation needs at least one active Type and Category.
     */
    public function mastersReady(): bool
    {
        return $this->options['types']->isNotEmpty() && $this->options['categories']->isNotEmpty();
    }

    public function updatedProjectId(): void
    {
        abort_if($this->lockedProjectId !== null, 403);

        $this->task_id = '';

        if ($this->client_id === '' && $this->project?->client_id !== null) {
            $this->client_id = (string) $this->project->client_id;
        }
    }

    public function updatedTaskId(): void
    {
        abort_if($this->lockedTaskId !== null, 403);

        if ($this->task_id !== '' && $this->project_id === '') {
            $projectId = Task::query()->visibleTo(auth()->user())->whereKey((int) $this->task_id)->value('project_id');

            if ($projectId !== null) {
                $taskId = $this->task_id;
                $this->project_id = (string) $projectId;
                $this->updatedProjectId();
                $this->task_id = $taskId;
            }
        }
    }

    public function updatedTicketCategoryId(): void
    {
        $this->ticket_subcategory_id = '';
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function save(CreateTicket $createTicket): void
    {
        $this->authorize('create', Ticket::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'ticket_type_id' => ['required', 'integer'],
            'ticket_category_id' => ['required', 'integer'],
            'priority_id' => ['required', 'integer'],
            'current_department_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:10000'],
            'client_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'ticket_subcategory_id' => ['nullable', 'integer'],
            'ticket_source_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'workflow_id' => ['nullable', 'integer'],
            'files' => ['array', 'max:'.AttachmentRules::maxPerUpload()],
            'files.*' => AttachmentRules::file(),
        ], [
            'ticket_type_id.required' => 'Pilih jenis report.',
            'current_department_id.required' => 'Pilih Department tujuan yang akan menangani report ini.',
            'due_date.after_or_equal' => 'Deadline tidak boleh di masa lalu.',
        ], [
            'title' => 'Title', 'ticket_type_id' => 'Type', 'ticket_category_id' => 'Category', 'priority_id' => 'Priority',
            'description' => 'Description', 'due_date' => 'Due date', 'files.*' => 'Attachment',
        ]);

        $ticket = $createTicket->handle([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'ticket_type_id' => (int) $validated['ticket_type_id'],
            'ticket_category_id' => (int) $validated['ticket_category_id'],
            'priority_id' => (int) $validated['priority_id'],
            'current_department_id' => (int) $validated['current_department_id'],
            'ticket_subcategory_id' => filled($validated['ticket_subcategory_id']) ? (int) $validated['ticket_subcategory_id'] : null,
            'ticket_source_id' => filled($validated['ticket_source_id']) ? (int) $validated['ticket_source_id'] : null,
            'client_id' => filled($validated['client_id']) ? (int) $validated['client_id'] : null,
            'project_id' => filled($validated['project_id']) ? (int) $validated['project_id'] : null,
            'task_id' => filled($validated['task_id']) ? (int) $validated['task_id'] : null,
            'due_date' => $validated['due_date'] ?: null,
            'workflow_id' => filled($validated['workflow_id']) ? (int) $validated['workflow_id'] : null,
        ], auth()->user(), array_values($this->files));

        $this->notifyAfterRedirect('success', 'Laporan '.$ticket->number.' berhasil dibuat.');
        $this->redirectRoute('tickets.show', $ticket, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-3xl">
    <x-ui.page-header title="New Report" description="Bug, maintenance, request, issue, atau catatan pekerjaan. Report langsung masuk ke Department Inbox tujuan.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                @if ($this->project && $lockedProjectId)
                    <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item :href="route('projects.show', ['project' => $this->project, 'tab' => 'reports'])" wire:navigate>{{ $this->project->name }}</flux:breadcrumbs.item>
                @else
                    <flux:breadcrumbs.item :href="route('tickets.index')" wire:navigate>Reports</flux:breadcrumbs.item>
                @endif
                <flux:breadcrumbs.item>New</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-5 sm:p-8">
        @if ($this->options['types']->isEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Master Ticket Type belum tersedia.">
                <flux:callout.text>Hubungi administrator untuk mengatur Master Data Ticket.</flux:callout.text>
            </flux:callout>
        @endif
        @if ($this->options['categories']->isEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Master Ticket Category belum tersedia.">
                <flux:callout.text>Hubungi administrator untuk mengatur Master Data Ticket.</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Type first: what is being reported (types come from the master) --}}
        @if ($this->options['types']->isNotEmpty())
            <flux:field>
                <flux:label badge="Required">What are you reporting?</flux:label>
                <div role="radiogroup" aria-label="Report type" class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($this->options['types'] as $option)
                        <label wire:key="ty-{{ $option->id }}"
                            @class([
                                'flex min-h-12 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition focus-within:ring-2 focus-within:ring-accent',
                                'border-accent bg-zinc-50 font-medium text-zinc-900' => $ticket_type_id === (string) $option->id,
                                'border-zinc-200 text-zinc-700 hover:border-zinc-300' => $ticket_type_id !== (string) $option->id,
                            ])
                            @if ($option->description) title="{{ $option->description }}" @endif>
                            <input type="radio" wire:model.live="ticket_type_id" value="{{ $option->id }}" class="sr-only" />
                            <span class="min-w-0 truncate">{{ $option->name }}</span>
                            @if ($ticket_type_id === (string) $option->id)
                                <flux:icon.check variant="micro" class="ms-auto shrink-0 text-accent" />
                            @endif
                        </label>
                    @endforeach
                </div>
                <flux:error name="ticket_type_id" />
            </flux:field>
        @endif

        <flux:input wire:model="title" label="Title" badge="Required" maxlength="200" placeholder="Contoh: Error jurnal saat pembayaran POS" />

        {{-- Related work: fixed when the report starts from a project or task --}}
        @if ($lockedProjectId || $lockedTaskId)
            <div class="grid gap-3 rounded-lg bg-zinc-50 px-4 py-3 text-sm sm:grid-cols-2">
                @if ($this->project)
                    <div class="min-w-0">
                        <span class="text-zinc-500">Project</span>
                        <div class="truncate font-medium text-zinc-900">{{ $this->project->number }} · {{ $this->project->name }}</div>
                    </div>
                @endif
                @if ($this->lockedTask)
                    <div class="min-w-0">
                        <span class="text-zinc-500">Related Task</span>
                        <div class="truncate font-medium text-zinc-900">{{ $this->lockedTask->number }} · {{ $this->lockedTask->title }}</div>
                    </div>
                @elseif ($this->project)
                    <flux:select wire:model.live="task_id" label="Related Task" size="sm">
                        <flux:select.option value="">Tanpa task</flux:select.option>
                        @foreach ($this->taskOptions as $option)
                            <flux:select.option :value="$option->id" wire:key="tk-{{ $option->id }}">{{ $option->number }} · {{ $option->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="project_id" label="Project" description="Opsional. Hanya project aktif yang dapat Anda lihat.">
                    <flux:select.option value="">Tanpa project</flux:select.option>
                    @foreach ($this->projectOptions as $option)
                        <flux:select.option :value="$option->id" wire:key="pj-{{ $option->id }}">{{ $option->number }} · {{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="task_id" label="Related Task" :description="$project_id !== '' ? 'Task dari project yang dipilih.' : 'Memilih task juga mengisi project-nya.'">
                    <flux:select.option value="">Tanpa task</flux:select.option>
                    @foreach ($this->taskOptions as $option)
                        <flux:select.option :value="$option->id" wire:key="tk-{{ $option->id }}">{{ $option->number }} · {{ $option->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <livewire:department-picker wire:model="current_department_id" label="Target Department" :required="true" key="ticket-target-department" />
                <flux:error name="current_department_id" />
            </div>
            @if ($this->options['categories']->count() !== 1)
                <flux:select wire:model.live="ticket_category_id" label="Category" badge="Required" placeholder="Pilih category">
                    @foreach ($this->options['categories'] as $option)
                        <flux:select.option :value="$option->id" wire:key="ca-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <flux:textarea wire:model="description" label="Description" badge="Required" rows="5" placeholder="Jelaskan masalah atau kebutuhan, langkah yang dilakukan, dan hasil yang diharapkan." />

        <div class="border-t border-zinc-100 pt-4">
            <flux:button variant="ghost" size="sm" :icon="$showMore ? 'chevron-up' : 'chevron-down'" wire:click="$toggle('showMore')">More options</flux:button>
            <flux:text size="sm" class="mt-1">Priority, client, deadline, dan lampiran.</flux:text>
        </div>

        @if ($showMore)
            <div class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="priority_id" label="Priority">
                        @foreach ($this->options['priorities'] as $option)
                            <flux:select.option :value="$option->id" wire:key="pr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="due_date" type="date" label="Due date" :min="today()->toDateString()" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="client_id" label="Client" description="Opsional, misalnya client yang melaporkan.">
                        <flux:select.option value="">Tanpa client</flux:select.option>
                        @foreach ($this->options['clients'] as $option)
                            <flux:select.option :value="$option->id" wire:key="cl-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="ticket_subcategory_id" label="Subcategory" :disabled="$ticket_category_id === ''" :description="$ticket_category_id === '' ? 'Pilih category terlebih dahulu.' : null">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->options['subcategories'] as $option)
                            <flux:select.option :value="$option->id" wire:key="sc-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="ticket_source_id" label="Source">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->options['sources'] as $option)
                            <flux:select.option :value="$option->id" wire:key="so-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($this->processes->count() > 1)
                        <flux:select wire:model="workflow_id" label="Process" description="Biarkan default kecuali report ini membutuhkan proses khusus.">
                            <flux:select.option value="">{{ $this->processes->firstWhere('is_default', true)?->name ?? 'Default' }} (default)</flux:select.option>
                            @foreach ($this->processes->where('is_default', false) as $process)
                                <flux:select.option :value="$process->id" wire:key="wf-{{ $process->id }}">{{ $process->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
                <div>
                    <flux:field>
                        <flux:label>Attachments</flux:label>
                        <flux:description>{{ \App\Domain\Ticket\Rules\AttachmentRules::label() }} · maks {{ intdiv(\App\Domain\Ticket\Rules\AttachmentRules::maxKilobytes(), 1024) }} MB per file · maks {{ \App\Domain\Ticket\Rules\AttachmentRules::maxPerUpload() }} file</flux:description>
                        <input type="file" wire:model="files" multiple class="block w-full text-sm text-zinc-600 file:me-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200" />
                        <div wire:loading wire:target="files" class="text-xs text-zinc-500">Mengunggah...</div>
                        <flux:error name="files" />
                        @foreach ($errors->get('files.*') as $messages)
                            <flux:error :message="$messages[0]" />
                        @endforeach
                    </flux:field>
                    @if ($files !== [])
                        <ul class="mt-2 space-y-1">
                            @foreach ($files as $index => $file)
                                <li wire:key="file-{{ $index }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm">
                                    <span class="min-w-0 truncate">{{ $file->getClientOriginalName() }}</span>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeFile({{ $index }})" aria-label="Remove file" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="$lockedTaskId ? route('tasks.show', $lockedTaskId) : ($lockedProjectId ? route('projects.show', ['project' => $lockedProjectId, 'tab' => 'reports']) : route('tickets.index'))" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,files" :disabled="! $this->mastersReady()">
                <span wire:loading.remove wire:target="save">Submit Report</span>
                <span wire:loading wire:target="save">Submitting...</span>
            </flux:button>
        </div>
    </form>
</div>
