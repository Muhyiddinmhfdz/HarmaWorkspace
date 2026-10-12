<?php

use App\Models\Ticket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My Reports')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="My Reports" description="Report yang Anda buat atau tangani. Yang menunggu tindakan Anda tampil paling atas." />

    <livewire:tickets.table mode="mine" />
</div>
