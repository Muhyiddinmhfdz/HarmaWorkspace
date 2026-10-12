<?php

use App\Domain\Approval\Actions\CancelApprovalRequest;
use App\Domain\Approval\Actions\DecideApproval;
use App\Domain\Approval\Support\ApproverResolver;
use App\Domain\Workflow\Support\RecordPresenter;
use App\Enums\ApprovalDecisionType;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public int $requestId;

    #[Locked]
    public ?string $decision = null;

    public string $comment = '';

    public string $cancelReason = '';

    public function mount(ApprovalRequest $approvalRequest): void
    {
        $this->authorize('view', $approvalRequest);
        $this->requestId = $approvalRequest->id;
    }

    #[Computed]
    public function request(): ApprovalRequest
    {
        return ApprovalRequest::query()
            ->with(['approvable', 'requester:id,name', 'template:id,name', 'transition.fromState.status', 'transition.toState.status', 'steps.decisions.user:id,name'])
            ->findOrFail($this->requestId);
    }

    #[Computed]
    public function record(): Model
    {
        return $this->request->approvable ?? abort(404);
    }

    /**
     * @return array{decide: bool, cancel: bool}
     */
    #[Computed]
    public function can(): array
    {
        return [
            'decide' => auth()->user()->can('decide', $this->request),
            'cancel' => auth()->user()->can('cancel', $this->request),
        ];
    }

    public function waitingFor(ApprovalStep $step): string
    {
        $resolver = app(ApproverResolver::class);
        $candidates = $resolver->candidates($step, $this->request, $this->record);

        if ($candidates === null) {
            return $resolver->describe($step, $this->record);
        }

        if ($candidates->isEmpty()) {
            return 'Approver unavailable — tidak ada approver aktif yang dapat memutuskan. Hubungi administrator.';
        }

        return $resolver->describe($step, $this->record).': '.$candidates->pluck('name')->join(', ');
    }

    public function isSelfRequest(): bool
    {
        $step = $this->request->steps->firstWhere('status.value', 'pending');

        return $step !== null && ! $step->allow_self_approval && $this->request->requested_by === auth()->id();
    }

    public function open(string $decision): void
    {
        abort_unless(in_array($decision, ['approve', 'reject', 'revision'], true), 404);
        $this->authorize('decide', $this->request);

        $this->decision = $decision;
        $this->comment = '';
        $this->resetValidation();
        Flux::modal('approval-decision')->show();
    }

    public function decide(DecideApproval $decide): void
    {
        $type = ApprovalDecisionType::from((string) $this->decision);
        $this->authorize('decide', $this->request);
        $this->validate(['comment' => [$type->requiresComment() ? 'required' : 'nullable', 'string', 'max:5000']], ['comment.required' => $type === ApprovalDecisionType::Reject ? 'Alasan penolakan wajib diisi.' : 'Catatan revisi wajib diisi.'], ['comment' => 'Comment']);

        $message = $decide->handle($this->request, $type, auth()->user(), $this->comment ?: null);

        Flux::modal('approval-decision')->close();
        $this->notify(str_contains($message, 'tidak dijalankan') ? 'warning' : 'success', $message);
        $this->decision = null;
        unset($this->request, $this->can);
    }

    public function cancel(CancelApprovalRequest $cancel): void
    {
        $this->authorize('cancel', $this->request);
        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], attributes: ['cancelReason' => 'Reason']);

        $cancel->handle($this->request, auth()->user(), $this->cancelReason);

        Flux::modal('approval-cancel')->close();
        $this->notify('success', 'Permintaan approval dibatalkan.');
        unset($this->request, $this->can);
    }

    public function presenter(): RecordPresenter
    {
        return app(RecordPresenter::class);
    }

    public function render()
    {
        return $this->view()->title('Approval · '.$this->request->title);
    }
}; ?>

@php($request = $this->request)
@php($record = $this->record)
@php($presenter = $this->presenter())

<div class="mx-auto w-full max-w-4xl">
    <div class="mb-6">
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('approvals.mine')" wire:navigate>My Approvals</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $presenter->number($record) }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                @can('view', $record)
                    <a href="{{ $presenter->url($record) }}" wire:navigate class="font-mono text-sm text-zinc-500 hover:underline">{{ $presenter->type($record) }} · {{ $presenter->number($record) }} — {{ $presenter->title($record) }}</a>
                @else
                    <div class="font-mono text-sm text-zinc-500">{{ $presenter->type($record) }} · {{ $presenter->number($record) }} — {{ $presenter->title($record) }}</div>
                    <div class="text-xs text-zinc-400">Anda ditunjuk sebagai approver; detail lengkap record tidak dapat Anda buka.</div>
                @endcan
                <flux:heading size="xl" level="1" class="mt-0.5">{{ $request->title }}</flux:heading>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-zinc-600">
                    <flux:badge size="sm" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge>
                    <span>Diminta {{ $request->requester->name }} · {{ $request->requested_at->translatedFormat('d M Y H:i') }}</span>
                </div>
                @if ($request->transition)
                    <flux:text class="mt-1">{{ $request->transition->fromState->label() }} → {{ $request->transition->toState->label() }}</flux:text>
                @endif
            </div>
            @if ($this->can['decide'])
                <div class="flex shrink-0 flex-wrap gap-2">
                    <flux:button variant="primary" icon="check" wire:click="open('approve')">Approve</flux:button>
                    <flux:button wire:click="open('revision')">Request Revision</flux:button>
                    <flux:button variant="danger" wire:click="open('reject')">Reject</flux:button>
                </div>
            @endif
        </div>
    </div>

    @if ($request->isPending() && $this->isSelfRequest())
        <flux:callout icon="information-circle" class="mb-6" heading="Menunggu approver lain">
            <flux:callout.text>Anda adalah peminta approval ini. Tahap saat ini tidak mengizinkan menyetujui permintaan sendiri.</flux:callout.text>
        </flux:callout>
    @endif

    @if ($request->outcome_note)
        <flux:callout :variant="$request->status->value === 'cancelled' ? 'warning' : 'secondary'" icon="exclamation-triangle" class="mb-6" heading="Catatan">
            <flux:callout.text>{{ $request->outcome_note }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (($request->input['comment'] ?? $request->input['reason'] ?? null) !== null)
        <flux:card class="mb-6">
            <flux:heading size="sm">Catatan peminta</flux:heading>
            <flux:text class="mt-1 whitespace-pre-line">{{ $request->input['comment'] ?? $request->input['reason'] }}</flux:text>
        </flux:card>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-5">
        <flux:heading size="lg">Review Progress</flux:heading>
        <ol class="mt-4 space-y-5">
            @foreach ($request->steps as $step)
                <li wire:key="st-{{ $step->id }}" class="flex gap-3">
                    @switch($step->status->value)
                        @case('approved')
                            <flux:icon.check-circle variant="solid" class="size-6 shrink-0 text-green-600" />
                            @break
                        @case('pending')
                            <flux:icon.ellipsis-horizontal-circle variant="solid" class="size-6 shrink-0 text-amber-500" />
                            @break
                        @case('rejected')
                            <flux:icon.x-circle variant="solid" class="size-6 shrink-0 text-red-600" />
                            @break
                        @case('revision_requested')
                            <flux:icon.arrow-uturn-left class="size-6 shrink-0 text-orange-500" />
                            @break
                        @case('skipped')
                            <flux:icon.minus-circle class="size-6 shrink-0 text-zinc-300" />
                            @break
                        @default
                            <span class="mt-1 size-5 shrink-0 rounded-full border-2 border-zinc-300"></span>
                    @endswitch
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-zinc-900">Step {{ $step->step_order }} · {{ $step->name }}</span>
                            @if ($step->minimum_approvals > 1)
                                <flux:badge size="sm" color="zinc">{{ $step->minimum_approvals }} approvals</flux:badge>
                            @endif
                        </div>
                        @if ($step->status->value === 'pending')
                            <div class="mt-0.5 text-sm text-zinc-600">Menunggu: {{ $this->waitingFor($step) }}</div>
                        @elseif ($step->status->value === 'waiting')
                            <div class="mt-0.5 text-sm text-zinc-500">{{ app(\App\Domain\Approval\Support\ApproverResolver::class)->describe($step, $record) }}</div>
                        @endif
                        @foreach ($step->decisions as $decision)
                            <div wire:key="dc-{{ $decision->id }}" class="mt-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm">
                                <div><span class="font-medium">{{ $decision->user->name }}</span> {{ strtolower($decision->decision->label()) }} · <span class="text-zinc-500">{{ $decision->decided_at->translatedFormat('d M H:i') }}</span></div>
                                @if ($decision->comment)
                                    <div class="mt-1 whitespace-pre-line text-zinc-700">{{ $decision->comment }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($this->can['cancel'])
        <div class="mt-4 text-end">
            <flux:button variant="ghost" size="sm" x-on:click="$flux.modal('approval-cancel').show()">Cancel request</flux:button>
        </div>
    @endif

    <flux:modal name="approval-decision" class="w-full max-w-md">
        <form wire:submit="decide" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ match ($decision) { 'reject' => 'Reject', 'revision' => 'Request Revision', default => 'Approve' } }}</flux:heading>
                <flux:text class="mt-1">{{ match ($decision) { 'reject' => 'Permintaan berakhir dan record tetap pada status saat ini.', 'revision' => 'Peminta memperbaiki lalu mengajukan approval baru.', default => 'Keputusan tidak dapat diubah setelah disimpan.' } }}</flux:text>
            </div>
            <flux:textarea wire:model="comment" :label="$decision === 'approve' ? 'Comment' : 'Reason'" :badge="$decision === 'approve' ? null : 'Required'" rows="4" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" :variant="$decision === 'reject' ? 'danger' : 'primary'" wire:loading.attr="disabled" wire:target="decide">Submit</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="approval-cancel" class="w-full max-w-md">
        <form wire:submit="cancel" class="space-y-6">
            <flux:heading size="lg">Cancel approval request?</flux:heading>
            <flux:textarea wire:model="cancelReason" label="Reason" badge="Required" rows="3" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">Cancel request</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
