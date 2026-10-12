<?php

use App\Domain\Ticket\Actions\AddTicketAttachment;
use App\Domain\Ticket\Rules\AttachmentRules;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ticket attachments: private files listed with their display name; the
 * download goes through the authorized route, never a storage URL.
 */
new class extends Component {
    use WithFeedback, WithFileUploads;

    #[Locked]
    public int $ticketId;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->authorize('view', $this->ticket());
    }

    private function ticket(): Ticket
    {
        return Ticket::query()->with('status')->findOrFail($this->ticketId);
    }

    #[On('ticket-updated')]
    public function refreshFiles(): void
    {
        unset($this->files);
    }

    /**
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function files(): Collection
    {
        return Attachment::query()
            ->where('attachable_type', 'ticket')
            ->where('attachable_id', $this->ticketId)
            ->with('uploader:id,name')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function canUpload(): bool
    {
        return auth()->user()->can('comment', $this->ticket());
    }

    public function updatedUploads(AddTicketAttachment $addAttachment): void
    {
        $ticket = $this->ticket();
        $this->authorize('comment', $ticket);

        $this->validate([
            'uploads' => ['array', 'max:'.AttachmentRules::maxPerUpload()],
            'uploads.*' => AttachmentRules::file(),
        ], attributes: ['uploads.*' => 'Attachment']);

        foreach ($this->uploads as $upload) {
            $addAttachment->store($ticket, $upload, auth()->user());
        }

        $count = count($this->uploads);
        $this->uploads = [];
        $this->notify('success', $count.' lampiran berhasil diunggah.');
        $this->refreshFiles();
        $this->dispatch('ticket-updated');
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="mb-3 flex items-center justify-between gap-3">
        <flux:heading size="lg">Attachments</flux:heading>
        @if ($this->canUpload)
            <label class="cursor-pointer">
                <input type="file" wire:model="uploads" multiple class="sr-only" />
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
                    <flux:icon.paper-clip variant="micro" />
                    <span wire:loading.remove wire:target="uploads">Upload</span>
                    <span wire:loading wire:target="uploads">Mengunggah...</span>
                </span>
            </label>
        @endif
    </div>

    <flux:error name="uploads" />
    @foreach ($errors->get('uploads.*') as $messages)
        <flux:error :message="$messages[0]" />
    @endforeach

    <ul class="divide-y divide-zinc-100">
        @forelse ($this->files as $file)
            <li wire:key="att-{{ $file->id }}" class="flex items-center justify-between gap-3 py-2.5">
                <div class="flex min-w-0 items-center gap-3">
                    <flux:icon :icon="$file->isImage() ? 'photo' : 'document-text'" class="size-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0">
                        <a href="{{ route('tickets.attachments.download', [$ticketId, $file->id]) }}" class="block truncate text-sm font-medium text-zinc-800 hover:underline">{{ $file->original_name }}</a>
                        <div class="text-xs text-zinc-500">{{ $file->humanSize() }} · Uploaded by {{ $file->uploader->name }} · {{ $file->created_at?->translatedFormat('d M Y') }}</div>
                    </div>
                </div>
                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('tickets.attachments.download', [$ticketId, $file->id])" aria-label="Download {{ $file->original_name }}" />
            </li>
        @empty
            <li class="py-2 text-sm text-zinc-500">Belum ada lampiran. {{ \App\Domain\Ticket\Rules\AttachmentRules::label() }}, maks {{ intdiv(\App\Domain\Ticket\Rules\AttachmentRules::maxKilobytes(), 1024) }} MB per file.</li>
        @endforelse
    </ul>
</div>
