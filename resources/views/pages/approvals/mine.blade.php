<?php

use App\Domain\Approval\Support\ApprovalInbox;
use App\Domain\Workflow\Support\RecordPresenter;
use App\Enums\ApprovalRequestStatus;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('My Approvals')] class extends Component {
    #[Url(as: 'view', except: 'waiting')]
    public string $section = 'waiting';

    public function mount(): void
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        if (! in_array($this->section, ['waiting', 'requested', 'completed'], true)) {
            $this->section = 'waiting';
        }
    }

    /**
     * @return Collection<int, ApprovalRequest>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return app(ApprovalInbox::class)->waitingFor(auth()->user());
    }

    /**
     * @return Collection<int, ApprovalRequest>
     */
    #[Computed]
    public function requested(): Collection
    {
        return ApprovalRequest::query()
            ->where('requested_by', auth()->id())
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->with(['approvable', 'requester:id,name', 'currentStep', 'steps'])
            ->latest('requested_at')
            ->limit(100)
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $request->approvable !== null)
            ->values();
    }

    /**
     * Finished requests the user asked for or decided on.
     *
     * @return Collection<int, ApprovalRequest>
     */
    #[Computed]
    public function completed(): Collection
    {
        $userId = auth()->id();

        return ApprovalRequest::query()
            ->where('status', '!=', ApprovalRequestStatus::Pending->value)
            ->where(fn (Builder $query) => $query
                ->where('requested_by', $userId)
                ->orWhereIn('id', ApprovalDecision::query()
                    ->join('approval_steps', 'approval_steps.id', '=', 'approval_decisions.approval_step_id')
                    ->where('approval_decisions.user_id', $userId)
                    ->select('approval_steps.approval_request_id')))
            ->with(['approvable', 'requester:id,name', 'steps'])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $request->approvable !== null && auth()->user()->can('view', $request))
            ->values();
    }

    /**
     * @return Collection<int, ApprovalRequest>
     */
    public function rows(): Collection
    {
        return match ($this->section) {
            'requested' => $this->requested,
            'completed' => $this->completed,
            default => $this->waiting,
        };
    }

    public function presenter(): RecordPresenter
    {
        return app(RecordPresenter::class);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header title="My Approvals" description="Permintaan yang menunggu keputusan Anda, yang Anda ajukan, dan yang sudah selesai." />

    <div class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <nav class="flex min-w-max gap-1 border-b border-zinc-200" aria-label="Approval views">
            @foreach (['waiting' => ['Waiting for Me', $this->waiting->count()], 'requested' => ['Requested by Me', $this->requested->count()], 'completed' => ['Completed', null]] as $key => [$label, $count])
                <button type="button" wire:key="as-{{ $key }}" wire:click="$set('section', '{{ $key }}')"
                    @class(['-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap', 'border-accent text-zinc-900' => $section === $key, 'border-transparent text-zinc-500 hover:text-zinc-800' => $section !== $key])>
                    {{ $label }}
                    @if ($count !== null)
                        <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-zinc-900 text-white' => $section === $key, 'bg-zinc-100 text-zinc-600' => $section !== $key])>{{ $count }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    @php($rows = $this->rows())
    @if ($rows->isEmpty())
        <x-ui.table-card>
            <x-ui.empty-state icon="check-badge"
                :title="match ($section) { 'requested' => 'Tidak ada permintaan yang sedang berjalan.', 'completed' => 'Belum ada approval yang selesai.', default => 'Tidak ada approval yang menunggu Anda.' }"
                description="Approval muncul di sini ketika sebuah aksi Project, Task, atau Report membutuhkan persetujuan." />
        </x-ui.table-card>
    @else
        <x-ui.table-card class="hidden md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="ps-5">Record</flux:table.column>
                    <flux:table.column>Request</flux:table.column>
                    <flux:table.column>Requested by</flux:table.column>
                    <flux:table.column>Requested at</flux:table.column>
                    <flux:table.column class="pe-5">{{ $section === 'completed' ? 'Result' : 'Current step' }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $request)
                        <flux:table.row :key="$request->id" class="cursor-pointer hover:bg-zinc-50" x-data x-on:click="Livewire.navigate('{{ route('approvals.show', $request) }}')">
                            <flux:table.cell class="max-w-72 ps-5">
                                <div class="font-mono text-xs text-zinc-500">{{ $this->presenter()->type($request->approvable) }} · {{ $this->presenter()->number($request->approvable) }}</div>
                                <a href="{{ route('approvals.show', $request) }}" wire:navigate x-on:click.stop class="block truncate font-medium text-zinc-900 hover:underline">{{ $this->presenter()->title($request->approvable) }}</a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $request->title }}</flux:table.cell>
                            <flux:table.cell>{{ $request->requester->name }}</flux:table.cell>
                            <flux:table.cell><span title="{{ $request->requested_at->translatedFormat('d M Y H:i') }}">{{ $request->requested_at->diffForHumans() }}</span></flux:table.cell>
                            <flux:table.cell class="pe-5">
                                @if ($section === 'completed')
                                    <flux:badge size="sm" :color="$request->status->color()" inset="top bottom">{{ $request->status->label() }}</flux:badge>
                                @else
                                    {{ $request->currentStep?->name }} <span class="text-xs text-zinc-500">({{ $request->current_step_order }}/{{ $request->steps->count() }})</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table-card>

        <div class="space-y-3 md:hidden">
            @foreach ($rows as $request)
                <a href="{{ route('approvals.show', $request) }}" wire:navigate wire:key="ac-{{ $request->id }}" class="block rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-zinc-900">{{ $request->title }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $this->presenter()->number($request->approvable) }} · {{ $this->presenter()->title($request->approvable) }}</div>
                        </div>
                        <flux:badge size="sm" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge>
                    </div>
                    <div class="mt-2 flex justify-between gap-3 text-xs text-zinc-500">
                        <span class="truncate">{{ $request->requester->name }}</span>
                        <span class="shrink-0">{{ $section === 'completed' ? $request->updated_at?->diffForHumans() : ($request->currentStep?->name ?? '') }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
