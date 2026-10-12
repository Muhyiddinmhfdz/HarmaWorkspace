<?php

use App\Domain\Project\Actions\ChangeProjectManager;
use App\Domain\Project\Actions\ChangeProjectOwner;
use App\Domain\Project\Actions\UpdateProject;
use App\Domain\Project\Rules\ProjectRules;
use App\Enums\MasterScope;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Client;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $projectId;

    public string $name = '';

    public string $client_id = '';

    public string $project_category_id = '';

    public string $priority_id = '';

    public string $description = '';

    public ?int $owner_id = null;

    public ?int $project_manager_id = null;

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        abort_if($project->isArchived(), 403, 'Project sudah diarsipkan.');

        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->client_id = (string) $project->client_id;
        $this->project_category_id = (string) $project->project_category_id;
        $this->priority_id = (string) $project->priority_id;
        $this->description = (string) $project->description;
        $this->owner_id = $project->owner_id;
        $this->project_manager_id = $project->project_manager_id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    /**
     * Active options plus the values already stored on the project.
     *
     * @return array{clients: Collection<int, Client>, categories: Collection<int, ProjectCategory>, priorities: Collection<int, Priority>}
     */
    #[Computed]
    public function options(): array
    {
        $project = $this->project;

        return [
            'clients' => Client::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $project->client_id))->orderBy('name')->get(['id', 'name', 'is_active']),
            'categories' => ProjectCategory::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $project->project_category_id))->orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'priorities' => Priority::query()->forScope(MasterScope::Project)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $project->priority_id))->orderBy('level')->get(['id', 'name', 'is_active']),
        ];
    }

    public function save(UpdateProject $updateProject, ChangeProjectManager $changeManager, ChangeProjectOwner $changeOwner): void
    {
        $project = $this->project;
        $this->authorize('update', $project);

        $validated = $this->validate([
            ...ProjectRules::general($project->client_id, $project->project_category_id, $project->priority_id),
            ...ProjectRules::ownership($project->owner_id, $project->project_manager_id),
        ]);

        DB::transaction(function () use ($project, $validated, $updateProject, $changeManager, $changeOwner): void {
            $updateProject->handle($project, [
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'client_id' => filled($validated['client_id']) ? (int) $validated['client_id'] : null,
                'project_category_id' => (int) $validated['project_category_id'],
                'priority_id' => (int) $validated['priority_id'],
            ], auth()->user());

            $changeManager->handle($project, (int) $validated['project_manager_id'], auth()->user());
            $changeOwner->handle($project, filled($validated['owner_id']) ? (int) $validated['owner_id'] : null, auth()->user());
        });

        $this->notifyAfterRedirect('success', 'Project berhasil diperbarui.');
        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit '.$this->project->name);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="'Edit '.$this->project->name" description="Perbarui informasi umum dan penanggung jawab project.">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projects</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('projects.show', $this->project)" wire:navigate>{{ $this->project->number }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Edit</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
        <x-ui.form-section title="General" description="Identitas dan klasifikasi project.">
            <flux:input wire:model="name" label="Project name" badge="Required" maxlength="150" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="client_id" label="Client">
                    <flux:select.option value="">Internal Project</flux:select.option>
                    @foreach ($this->options['clients'] as $option)
                        <flux:select.option :value="$option->id" wire:key="c-{{ $option->id }}">{{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="project_category_id" label="Category" badge="Required">
                    @foreach ($this->options['categories'] as $option)
                        <flux:select.option :value="$option->id" wire:key="cat-{{ $option->id }}">{{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:radio.group wire:model="priority_id" label="Priority" variant="segmented">
                @foreach ($this->options['priorities'] as $option)
                    <flux:radio :value="(string) $option->id" :label="$option->name" wire:key="p-{{ $option->id }}" />
                @endforeach
            </flux:radio.group>
            <flux:textarea wire:model="description" label="Description" rows="4" />
        </x-ui.form-section>

        <x-ui.form-section title="Ownership" description="Perubahan Project Manager dan Owner tercatat di activity.">
            <div>
                <livewire:user-picker wire:model="project_manager_id" label="Project Manager" :required="true" key="edit-pm" />
                <flux:error name="project_manager_id" />
            </div>
            <div>
                <livewire:user-picker wire:model="owner_id" label="Project Owner" key="edit-owner" />
                <flux:error name="owner_id" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Departments, Schedule & Visibility" description="Dikelola langsung dari halaman project.">
            <flux:text>
                Lead & supporting department diatur di tab <span class="font-medium text-zinc-800">Team</span>.
                Jadwal dan visibility diubah lewat menu <span class="font-medium text-zinc-800">More</span> agar perubahannya tercatat.
                Visibility saat ini: <span class="font-medium text-zinc-800">{{ $this->project->visibility->label() }}</span>.
            </flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" icon="users" :href="route('projects.show', ['project' => $this->project, 'tab' => 'team'])" wire:navigate>Open Team</flux:button>
            </div>
        </x-ui.form-section>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" :href="route('projects.show', $this->project)" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
