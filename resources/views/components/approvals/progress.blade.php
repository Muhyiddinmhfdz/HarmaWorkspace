<?php

use App\Domain\Approval\Support\ApproverResolver;
use App\Enums\ApprovalRequestStatus;
use App\Enums\WorkflowEntity;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Approval progress of a record (ApprovalProgress): the pending request with
 * its steps, or the latest rejection/revision/cancellation so the reason is
 * visible. Hidden when the record never needed approval.
 */
new class extends Component {
    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    public string $spacing = '';

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->subjectType = WorkflowEntity::from($subjectType)->value;
        $this->subjectId = $subjectId;
        $this->authorize('view', $this->subject());
    }

    private function subject(): Model
    {
        $model = WorkflowEntity::from($this->subjectType)->modelClass();

        return $model::query()->findOrFail($this->subjectId);
    }

    #[On('task-updated')]
    #[On('ticket-updated')]
    #[On('project-updated')]
    public function refreshRequest(): void
    {
        unset($this->request);
    }

    #[Computed]
    public function request(): ?ApprovalRequest
    {
        $latest = ApprovalRequest::query()
            ->where('approvable_type', $this->subjectType)
            ->where('approvable_id', $this->subjectId)
            ->with(['requester:id,name', 'steps.decisions.user:id,name', 'transition.fromState'])
            ->latest('id')
            ->first();

        if ($latest === null || $latest->status === ApprovalRequestStatus::Approved) {
            return null;
        }

        // Ended requests stay visible while the record is still where it was.
        if (! $latest->isPending() && $latest->transition?->fromState?->status_id !== (int) $this->subject()->getAttribute('status_id')) {
            return null;
        }

        return $latest;
    }

    public function approverHint(\App\Models\ApprovalStep $step): string
    {
        return app(ApproverResolver::class)->describe($step, $this->subject());
    }
}; ?>

<div @class([$spacing => $this->request !== null, 'hidden' => $this->request === null])>
    @if ($request = $this->request)
        @php($steps = $request->steps)
        @php($done = $steps->filter(fn ($step) => $step->status->value === 'approved')->count())
        @if ($request->isPending())
            <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading size="lg">Approval Pending</flux:heading>
                        <flux:text class="mt-0.5">“{{ $request->title }}” diminta {{ $request->requester->name }} · {{ $done }} dari {{ $steps->count() }} tahap selesai</flux:text>
                    </div>
                    <flux:button size="sm" :href="route('approvals.show', $request)" wire:navigate>Open approval</flux:button>
                </div>
                <ol class="mt-4 space-y-3">
                    @foreach ($steps as $step)
                        <li wire:key="aps-{{ $step->id }}" class="flex gap-3">
                            @switch($step->status->value)
                                @case('approved')
                                    <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-green-600" />
                                    @break
                                @case('pending')
                                    <flux:icon.ellipsis-horizontal-circle variant="solid" class="size-5 shrink-0 text-amber-500" />
                                    @break
                                @case('skipped')
                                    <flux:icon.minus-circle class="size-5 shrink-0 text-zinc-300" />
                                    @break
                                @default
                                    <span class="mt-0.5 size-4 shrink-0 rounded-full border-2 border-zinc-300"></span>
                            @endswitch
                            <div class="min-w-0 text-sm">
                                <div class="font-medium text-zinc-900">{{ $step->name }}</div>
                                @forelse ($step->decisions as $decision)
                                    <div class="text-zinc-600" wire:key="apd-{{ $decision->id }}">{{ $decision->decision->label() }} oleh {{ $decision->user->name }} · {{ $decision->decided_at->translatedFormat('d M H:i') }}</div>
                                @empty
                                    <div class="text-zinc-500">{{ $step->status->value === 'pending' ? 'Menunggu: '.$this->approverHint($step) : ($step->status->value === 'skipped' ? 'Dilewati' : $this->approverHint($step)) }}</div>
                                @endforelse
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @else
            @php($decision = $steps->flatMap->decisions->sortByDesc('id')->first())
            <flux:callout :variant="$request->status->value === 'rejected' ? 'danger' : 'warning'" :icon="$request->status->value === 'rejected' ? 'x-circle' : 'arrow-uturn-left'"
                :heading="match ($request->status->value) { 'rejected' => 'Approval Rejected', 'revision_requested' => 'Revision Requested', default => 'Approval Cancelled' }">
                <flux:callout.text>
                    @if ($request->status->value === 'cancelled')
                        “{{ $request->title }}”: {{ $request->outcome_note }}
                    @elseif ($decision)
                        {{ $decision->user->name }}{{ $request->status->value === 'rejected' ? ' menolak' : ' meminta revisi' }} “{{ $request->title }}”:
                        <span class="mt-1 block whitespace-pre-line font-medium">“{{ $decision->comment }}”</span>
                    @endif
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('approvals.show', $request)" wire:navigate>Details</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @endif
</div>
