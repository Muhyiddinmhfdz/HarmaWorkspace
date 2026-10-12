<?php

use App\Domain\Ticket\Actions\LinkTicketWork;
use App\Domain\Ticket\Actions\UpdateTicket;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSource;
use App\Models\TicketSubcategory;
use App\Models\TicketType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $ticketId;

    public string $title = '';

    public string $description = '';

    public string $ticket_type_id = '';

    public string $ticket_category_id = '';

    public string $ticket_subcategory_id = '';

    public string $ticket_source_id = '';

    public string $client_id = '';

    public string $project_id = '';

    public string $task_id = '';

    public function mount(Ticket $ticket): void
    {
        $this->authorize('update', $ticket);

        $this->ticketId = $ticket->id;
        $this->title = $ticket->title;
        $this->description = $ticket->description;
        $this->ticket_type_id = (string) $ticket->ticket_type_id;
        $this->ticket_category_id = (string) $ticket->ticket_category_id;
        $this->ticket_subcategory_id = (string) $ticket->ticket_subcategory_id;
        $this->ticket_source_id = (string) $ticket->ticket_source_id;
        $this->client_id = (string) $ticket->client_id;
        $this->project_id = (string) $ticket->project_id;
        $this->task_id = (string) $ticket->task_id;
    }

    #[Computed]
    public function ticket(): Ticket
    {
        return Ticket::query()->with(['project:id,name', 'task:id,number,title'])->findOrFail($this->ticketId);
    }

    /**
     * Active options plus the values already stored on the ticket.
     *
     * @return array{types: Collection<int, TicketType>, categories: Collection<int, TicketCategory>, subcategories: Collection<int, TicketSubcategory>, sources: Collection<int, TicketSource>, clients: Collection<int, Client>, projects: Collection<int, Project>, tasks: Collection<int, Task>}
     */
    #[Computed]
    public function options(): array
    {
        $ticket = $this->ticket;
        $user = auth()->user();

        return [
            'types' => TicketType::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $ticket->ticket_type_id))->orderBy('sort_order')->get(['id', 'name']),
            'categories' => TicketCategory::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $ticket->ticket_category_id))->orderBy('sort_order')->get(['id', 'name']),
            'subcategories' => TicketSubcategory::query()
                ->where('ticket_category_id', (int) $this->ticket_category_id)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $ticket->ticket_subcategory_id))
                ->orderBy('sort_order')->get(['id', 'name']),
            'sources' => TicketSource::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $ticket->ticket_source_id))->orderBy('sort_order')->get(['id', 'name']),
            'clients' => Client::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $ticket->client_id))->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->where(fn ($query) => $query->where(fn ($open) => $open->visibleTo($user)->open())->orWhere('id', $ticket->project_id))->orderBy('name')->get(['id', 'number', 'name']),
            'tasks' => Task::query()
                ->where(fn ($query) => $query->visibleTo($user)->orWhere('id', $ticket->task_id))
                ->when($this->project_id !== '', fn ($query) => $query->where('project_id', (int) $this->project_id))
                ->latest('id')->limit(100)->get(['id', 'number', 'title']),
        ];
    }

    public function updatedTicketCategoryId(): void
    {
        $this->ticket_subcategory_id = '';
    }

    public function updatedProjectId(): void
    {
        $this->task_id = '';
    }

    public function save(UpdateTicket $updateTicket, LinkTicketWork $linkWork): void
    {
        $ticket = $this->ticket;
        $this->authorize('update', $ticket);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'ticket_type_id' => ['required', 'integer'],
            'ticket_category_id' => ['required', 'integer'],
            'ticket_subcategory_id' => ['nullable', 'integer'],
            'ticket_source_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
        ], attributes: ['title' => 'Title', 'ticket_type_id' => 'Type', 'ticket_category_id' => 'Category']);

        DB::transaction(function () use ($ticket, $validated, $updateTicket, $linkWork): void {
            $updateTicket->handle($ticket, [
                'title' => $validated['title'],
                'description' => $validated['description'],
                'ticket_type_id' => (int) $validated['ticket_type_id'],
                'ticket_category_id' => (int) $validated['ticket_category_id'],
                'ticket_subcategory_id' => filled($validated['ticket_subcategory_id']) ? (int) $validated['ticket_subcategory_id'] : null,
                'ticket_source_id' => filled($validated['ticket_source_id']) ? (int) $validated['ticket_source_id'] : null,
                'client_id' => filled($validated['client_id']) ? (int) $validated['client_id'] : null,
            ], auth()->user());

            $linkWork->handle(
                $ticket,
                filled($validated['project_id']) ? (int) $validated['project_id'] : null,
                filled($validated['task_id']) ? (int) $validated['task_id'] : null,
                auth()->user(),
            );
        });

        $this->notifyAfterRedirect('success', 'Laporan berhasil diperbarui.');
        $this->redirectRoute('tickets.show', $ticket, navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit '.$this->ticket->number);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="'Edit '.$this->ticket->number" :description="$this->ticket->title">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('tickets.index')" wire:navigate>Reports</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('tickets.show', $this->ticket)" wire:navigate>{{ $this->ticket->number }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Edit</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
        <x-ui.form-section title="General" description="Informasi utama report.">
            <flux:input wire:model="title" label="Title" badge="Required" maxlength="200" />
            <flux:textarea wire:model="description" label="Description" badge="Required" rows="5" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="ticket_type_id" label="Type">
                    @foreach ($this->options['types'] as $option)
                        <flux:select.option :value="$option->id" wire:key="t-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="ticket_source_id" label="Source">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->options['sources'] as $option)
                        <flux:select.option :value="$option->id" wire:key="s-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="ticket_category_id" label="Category">
                    @foreach ($this->options['categories'] as $option)
                        <flux:select.option :value="$option->id" wire:key="c-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="ticket_subcategory_id" label="Subcategory">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->options['subcategories'] as $option)
                        <flux:select.option :value="$option->id" wire:key="sc-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:select wire:model="client_id" label="Client">
                <flux:select.option value="">Tanpa client</flux:select.option>
                @foreach ($this->options['clients'] as $option)
                    <flux:select.option :value="$option->id" wire:key="cl-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-ui.form-section>

        <x-ui.form-section title="Related work" description="Project dan task terkait. Hanya yang dapat Anda akses.">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="project_id" label="Project">
                    <flux:select.option value="">Tanpa project</flux:select.option>
                    @foreach ($this->options['projects'] as $option)
                        <flux:select.option :value="$option->id" wire:key="p-{{ $option->id }}">{{ $option->number }} · {{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="task_id" label="Task">
                    <flux:select.option value="">Tanpa task</flux:select.option>
                    @foreach ($this->options['tasks'] as $option)
                        <flux:select.option :value="$option->id" wire:key="tk-{{ $option->id }}">{{ $option->number }} · {{ $option->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Status, Assignment & Deadline" description="Diatur dari halaman report agar riwayatnya tercatat.">
            <flux:text>Status, PIC, kolaborator, department, priority, dan deadline diubah langsung dari halaman detail report.</flux:text>
        </x-ui.form-section>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" :href="route('tickets.show', $this->ticket)" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
