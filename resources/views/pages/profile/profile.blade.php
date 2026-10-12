<?php

use App\Domain\Organization\Actions\UpdateOwnProfile;
use App\Models\DepartmentMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use App\Livewire\Concerns\WithFeedback;
use Livewire\Component;

new #[Title('My Profile')] class extends Component {
    use WithFeedback;

    public string $name = '';

    public string $phone = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->phone = (string) Auth::user()->phone;
    }

    /**
     * Departments, positions and leadership are read-only here; they are
     * managed by administrators.
     *
     * @return Collection<int, DepartmentMembership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return Auth::user()->activeMemberships()
            ->with(['department:id,code,name', 'position:id,name'])
            ->orderByDesc('is_primary')
            ->get();
    }

    public function updateProfileInformation(UpdateOwnProfile $updateProfile): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $updateProfile->handle(Auth::user(), $validated);

        $this->notify('success', 'Profil berhasil diperbarui.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::profile.layout heading="Profile" subheading="Perbarui nama dan nomor telepon Anda.">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Name" type="text" required autofocus autocomplete="name" />

            <flux:input
                :value="auth()->user()->username"
                label="Username"
                readonly
                disabled
                description="Username dipakai untuk login dan hanya dapat diubah oleh administrator."
            />

            <flux:input
                :value="auth()->user()->email"
                label="Email"
                type="email"
                readonly
                disabled
                description="Diatur oleh administrator. Dipakai untuk link reset password."
            />

            <flux:input wire:model="phone" label="Phone" type="tel" autocomplete="tel" />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-profile-button">
                    <span wire:loading.remove wire:target="updateProfileInformation">Save</span>
                    <span wire:loading wire:target="updateProfileInformation">Saving...</span>
                </flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading>Organization</flux:heading>
        <flux:subheading>Department dan position Anda diatur oleh administrator.</flux:subheading>

        <div class="mt-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->memberships as $membership)
                <div wire:key="membership-{{ $membership->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $membership->department->name }}</div>
                        <div class="text-sm text-zinc-500">{{ $membership->position?->name ?? 'Tanpa position' }}</div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        @if ($membership->is_primary)
                            <flux:badge size="sm" color="blue">Primary</flux:badge>
                        @endif
                        @if ($membership->is_leader)
                            <flux:badge size="sm" color="amber">Leader</flux:badge>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-zinc-500">Anda belum terdaftar di department mana pun.</div>
            @endforelse
        </div>
    </x-pages::profile.layout>
</section>
