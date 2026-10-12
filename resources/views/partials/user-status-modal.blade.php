{{-- Shared activate/deactivate confirmation. Expects $this->statusTarget and a changeStatus() action. --}}
<flux:modal name="user-status" class="w-full max-w-md">
    @if ($this->statusTarget)
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $this->statusTarget->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $this->statusTarget->name }}?</flux:heading>
                <flux:text class="mt-2">
                    @if ($this->statusTarget->is_active)
                        User tidak dapat login dan semua sesi aktifnya akan diakhiri. Data dan riwayat user tetap tersimpan.
                    @else
                        User dapat kembali login dengan password yang sama.
                    @endif
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button :variant="$this->statusTarget->is_active ? 'danger' : 'primary'" wire:click="changeStatus" wire:loading.attr="disabled" data-test="confirm-user-status">
                    {{ $this->statusTarget->is_active ? 'Deactivate' : 'Activate' }}
                </flux:button>
            </div>
        </div>
    @endif
</flux:modal>
