{{-- Generic workflow transition modal (WithWorkflowTransitions). --}}
<flux:modal name="workflow-transition" class="w-full max-w-md">
    @php($pending = $this->pendingTransition)
    @if ($pending)
        <form wire:submit="submitTransition" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $pending->action_label }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($pending->requiresApproval())
                        Aksi ini membutuhkan approval ({{ $pending->approvalTemplate?->name }}). Status berubah setelah semua tahap disetujui.
                    @elseif ($pending->confirmation_message)
                        {{ $pending->confirmation_message }}
                    @else
                        {{ $pending->fromState->label() }} → {{ $pending->toState->label() }}
                    @endif
                </flux:text>
            </div>
            @foreach ($this->transitionFields($pending) as $field)
                <flux:textarea wire:key="tf-{{ $field['name'] }}" wire:model="transitionInput.{{ $field['name'] }}" :label="$field['label']" :badge="$field['required'] ? 'Required' : null" rows="3" />
            @endforeach
            @if ($this->transitionFields($pending) === [])
                <flux:textarea wire:model="transitionInput.comment" label="Comment" description="Opsional, terlihat oleh approver." rows="3" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Back</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submitTransition">{{ $pending->requiresApproval() ? 'Request Approval' : $pending->action_label }}</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
