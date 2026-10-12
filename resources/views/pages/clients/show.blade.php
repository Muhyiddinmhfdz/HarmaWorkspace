<?php

use App\Models\Client;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public int $clientId;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);

        $this->clientId = $client->id;
    }

    #[Computed]
    public function client(): Client
    {
        return Client::findOrFail($this->clientId);
    }

    public function render()
    {
        return $this->view()->title($this->client->name);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl">
    <x-ui.page-header :title="$this->client->name">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('clients.index')" wire:navigate>Clients</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->client->code }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="h-fit lg:col-span-2">
            <flux:heading size="lg" class="mb-2">Client information</flux:heading>
            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <x-ui.detail-item label="Code"><span class="font-mono">{{ $this->client->code }}</span></x-ui.detail-item>
                <x-ui.detail-item label="Status"><x-ui.active-badge :active="$this->client->is_active" /></x-ui.detail-item>
                <x-ui.detail-item label="PIC">{{ $this->client->pic_name }}</x-ui.detail-item>
                <x-ui.detail-item label="Phone">{{ $this->client->phone }}</x-ui.detail-item>
                <x-ui.detail-item label="Email">{{ $this->client->email }}</x-ui.detail-item>
                <x-ui.detail-item label="Address"><span class="whitespace-pre-line">{{ $this->client->address }}</span></x-ui.detail-item>
                <x-ui.detail-item label="Notes"><span class="whitespace-pre-line">{{ $this->client->notes }}</span></x-ui.detail-item>
            </dl>
        </flux:card>

        <flux:card class="h-fit">
            <x-ui.empty-state icon="folder" title="Project & Ticket" description="Project dan ticket yang terkait dengan client ini akan tampil di sini mulai Phase 2." class="py-8" />
        </flux:card>
    </div>
</div>
