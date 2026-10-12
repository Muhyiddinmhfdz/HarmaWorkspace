<?php

use App\Domain\Ticket\Actions\AddTicketComment;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Ticket conversation: user messages only (system events live in Activity).
 * Newest messages are loaded first in pages; shown oldest → newest.
 */
new class extends Component {
    use WithFeedback;

    private const int PAGE_SIZE = 20;

    #[Locked]
    public int $ticketId;

    public int $limit = self::PAGE_SIZE;

    public string $body = '';

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
    public function refreshMessages(): void
    {
        unset($this->messages, $this->total);
    }

    /**
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function messages(): Collection
    {
        return Comment::query()
            ->where('commentable_type', 'ticket')
            ->where('commentable_id', $this->ticketId)
            ->with('user:id,name')
            ->latest('id')
            ->limit($this->limit)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function total(): int
    {
        return Comment::query()->where('commentable_type', 'ticket')->where('commentable_id', $this->ticketId)->count();
    }

    #[Computed]
    public function canComment(): bool
    {
        return auth()->user()->can('comment', $this->ticket());
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function send(AddTicketComment $addComment): void
    {
        $ticket = $this->ticket();
        $this->authorize('comment', $ticket);
        $this->validate(['body' => ['required', 'string', 'max:'.AddTicketComment::MAX_LENGTH]], attributes: ['body' => 'Message']);

        $addComment->handle($ticket, $this->body, auth()->user());

        $this->body = '';
        $this->refreshMessages();
        $this->dispatch('ticket-updated');
    }
}; ?>

<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="mb-4 flex items-center justify-between">
        <flux:heading size="lg">Conversation</flux:heading>
        <span class="text-xs text-zinc-500">{{ $this->total }} pesan</span>
    </div>

    @if ($this->total > $this->messages->count())
        <div class="mb-4 text-center">
            <flux:button size="sm" variant="ghost" wire:click="loadOlder">Tampilkan pesan sebelumnya</flux:button>
        </div>
    @endif

    <ol class="space-y-5">
        @forelse ($this->messages as $message)
            <li wire:key="msg-{{ $message->id }}" class="flex gap-3">
                <flux:avatar size="sm" :name="$message->user->name" :initials="\Illuminate\Support\Str::initials($message->user->name)" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 text-sm">
                        <span class="font-medium text-zinc-900">{{ $message->user->name }}</span>
                        <span class="text-xs text-zinc-400" title="{{ $message->created_at?->translatedFormat('d M Y H:i') }}">{{ $message->created_at?->translatedFormat('d M, H:i') }}</span>
                        @if ($message->kind->label())
                            <flux:badge size="sm" :color="$message->kind === \App\Enums\CommentKind::RevisionRequest ? 'amber' : 'sky'" inset="top bottom">{{ $message->kind->label() }}</flux:badge>
                        @endif
                    </div>
                    <div class="mt-1 text-sm whitespace-pre-line [overflow-wrap:anywhere] text-zinc-700">{{ $message->body }}</div>
                </div>
            </li>
        @empty
            <li class="text-sm text-zinc-500">Belum ada percakapan. Gunakan kolom di bawah untuk bertanya atau memberi informasi.</li>
        @endforelse
    </ol>

    @if ($this->canComment)
        <form wire:submit="send" class="mt-5 space-y-2 border-t border-zinc-100 pt-4">
            <flux:textarea wire:model="body" rows="3" placeholder="Tulis pesan..." aria-label="Message" />
            <div class="flex justify-end">
                <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send">Send</flux:button>
            </div>
        </form>
    @endif
</div>
