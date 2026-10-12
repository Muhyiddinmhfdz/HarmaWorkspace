<?php

use App\Models\Ticket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tickets')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Tickets" description="Semua ticket yang dapat Anda lihat, dari yang paling perlu ditindaklanjuti." />

    <livewire:tickets.table mode="all" />
</div>
