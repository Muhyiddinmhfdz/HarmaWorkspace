<?php

use App\Domain\Audit\AuditLogger;
use App\Models\Activity;
use Flux\Flux;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit Log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $subjectType = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $until = '';

    #[Locked]
    public ?int $detailId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('audit.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'event', 'subjectType', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'event', 'subjectType', 'from', 'until');
        $this->resetPage();
    }

    /**
     * Simple pagination avoids a COUNT(*) over a table that only grows.
     */
    #[Computed]
    public function entries(): Paginator
    {
        $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';

        return Activity::query()
            ->inLog(AuditLogger::LOG_NAME)
            ->with(['causer:id,name', 'subject'])
            ->when($this->event !== '', fn (Builder $query) => $query->where('event', $this->event))
            ->when($this->subjectType !== '', fn (Builder $query) => $query->where('subject_type', $this->subjectType))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('description', 'like', $search)
                    ->orWhereHasMorph('causer', ['user'], fn (Builder $query) => $query->where('name', 'like', $search))
            ))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->validDate($this->until), fn (Builder $query) => $query->where('created_at', '<=', $this->until.' 23:59:59'))
            ->latest('id')
            ->simplePaginate(25);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function eventOptions(): Collection
    {
        return Activity::query()->inLog(AuditLogger::LOG_NAME)->whereNotNull('event')->distinct()->orderBy('event')->pluck('event');
    }

    #[Computed]
    public function detail(): ?Activity
    {
        return $this->detailId ? Activity::query()->with(['causer:id,name,username', 'subject'])->find($this->detailId) : null;
    }

    public function showDetail(int $id): void
    {
        $this->detailId = $id;
        Flux::modal('audit-detail')->show();
    }

    public function subjectLabel(Activity $entry): string
    {
        if ($entry->subject_type === null) {
            return '—';
        }

        $name = $entry->subject?->getAttribute('name');

        return ucfirst(str_replace('_', ' ', $entry->subject_type)).($name ? ': '.$name : ' #'.$entry->subject_id);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Audit Log" description="Jejak perubahan penting untuk administrator. Password, token, dan secret tidak pernah dicatat." />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search description or actor..." clearable aria-label="Search audit log" />
        </div>
        <flux:select wire:model.live="event" aria-label="Filter event">
            <flux:select.option value="">All events</flux:select.option>
            @foreach ($this->eventOptions as $option)
                <flux:select.option :value="$option" wire:key="event-{{ $option }}">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="subjectType" aria-label="Filter subject">
            <flux:select.option value="">All subjects</flux:select.option>
            @foreach (['user' => 'User', 'department' => 'Department', 'position' => 'Position', 'client' => 'Client', 'role' => 'Role'] as $value => $label)
                <flux:select.option :value="$value" wire:key="subject-{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="grid grid-cols-2 gap-2">
            <flux:input wire:model.live="from" type="date" aria-label="From date" />
            <flux:input wire:model.live="until" type="date" aria-label="Until date" />
        </div>
    </div>

    <x-ui.table-card>
        @if ($this->entries->isEmpty())
            @if ($search !== '' || $event !== '' || $subjectType !== '' || $from !== '' || $until !== '')
                <x-ui.empty-state icon="magnifying-glass" title="Tidak ada catatan yang cocok" description="Coba ubah filter pencarian.">
                    <flux:button size="sm" wire:click="resetFilters">Reset filter</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="clipboard-document-list" title="Belum ada catatan audit" description="Perubahan user, department, position, client, role, dan settings akan tercatat di sini." />
            @endif
        @else
            <flux:table :paginate="$this->entries" class="[&_[data-flux-column]:first-child]:ps-5 [&_[data-flux-cell]:first-child]:ps-5 [&_[data-flux-column]:last-child]:pe-5 [&_[data-flux-cell]:last-child]:pe-5" pagination:class="px-5 pb-3">
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Actor</flux:table.column>
                    <flux:table.column>Event</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Subject</flux:table.column>
                    <flux:table.column class="max-md:hidden">Description</flux:table.column>
                    <flux:table.column class="max-xl:hidden">IP</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">Detail</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->entries as $entry)
                        <flux:table.row :key="$entry->id">
                            <flux:table.cell class="text-sm" title="{{ $entry->created_at->format('d M Y H:i:s') }}">{{ $entry->created_at->format('d M Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->causer?->name ?? 'System' }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" color="zinc" inset="top bottom" class="font-mono">{{ $entry->event ?? '—' }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="max-lg:hidden">{{ $this->subjectLabel($entry) }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden max-w-xs truncate">{{ $entry->description }}</flux:table.cell>
                            <flux:table.cell class="max-xl:hidden font-mono text-xs">{{ $entry->ip_address ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="ghost" icon="eye" inset="top bottom" wire:click="showDetail({{ $entry->id }})" aria-label="Show detail" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-ui.table-card>

    <flux:modal name="audit-detail" class="w-full max-w-2xl">
        @if ($this->detail)
            @php($changes = $this->detail->attribute_changes?->toArray() ?? [])
            @php($old = $changes['old'] ?? [])
            @php($new = $changes['attributes'] ?? [])
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->detail->description }}</flux:heading>
                    <flux:text class="mt-1 font-mono text-xs">{{ $this->detail->event }} · #{{ $this->detail->id }}</flux:text>
                </div>

                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <x-ui.detail-item label="Time">{{ $this->detail->created_at->format('d M Y H:i:s') }}</x-ui.detail-item>
                    <x-ui.detail-item label="Actor">{{ $this->detail->causer ? $this->detail->causer->name.' (@'.$this->detail->causer->username.')' : 'System' }}</x-ui.detail-item>
                    <x-ui.detail-item label="Subject">{{ $this->subjectLabel($this->detail) }}</x-ui.detail-item>
                    <x-ui.detail-item label="IP address">{{ $this->detail->ip_address }}</x-ui.detail-item>
                    <x-ui.detail-item label="User agent"><span class="break-all text-xs">{{ $this->detail->user_agent }}</span></x-ui.detail-item>
                </dl>

                @if ($old !== [] || $new !== [])
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full text-sm">
                            <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr><th class="px-3 py-2">Field</th><th class="px-3 py-2">Old</th><th class="px-3 py-2">New</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field)
                                    <tr wire:key="field-{{ $field }}" class="align-top">
                                        <td class="px-3 py-2 font-mono text-xs">{{ $field }}</td>
                                        <td class="px-3 py-2 text-red-700 dark:text-red-300"><pre class="whitespace-pre-wrap font-mono text-xs">{{ array_key_exists($field, $old) ? json_encode($old[$field], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre></td>
                                        <td class="px-3 py-2 text-green-700 dark:text-green-300"><pre class="whitespace-pre-wrap font-mono text-xs">{{ array_key_exists($field, $new) ? json_encode($new[$field], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($this->detail->properties?->isNotEmpty())
                    <div>
                        <flux:heading class="mb-2">Properties</flux:heading>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs dark:bg-zinc-800">{{ json_encode($this->detail->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
