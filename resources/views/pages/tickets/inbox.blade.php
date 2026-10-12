<?php

use App\Models\Ticket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Department Inbox')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Department Inbox" description="Antrian kerja department: report baru, yang belum punya PIC, yang sedang dikerjakan, dan yang menunggu." />

    <livewire:tickets.table mode="inbox" />
</div>
