<?php

use App\Models\Ticket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Reports" description="Bug, maintenance, request, issue, dan catatan pekerjaan yang dapat Anda lihat." />

    <livewire:tickets.table mode="all" />
</div>
