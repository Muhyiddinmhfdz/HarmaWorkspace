<?php

use App\Models\Ticket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My Tickets')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="My Tickets" description="Ticket yang Anda ajukan, pegang sebagai PIC, atau bantu sebagai kolaborator. Yang menunggu tindakan Anda tampil paling atas." />

    <livewire:tickets.table mode="mine" />
</div>
