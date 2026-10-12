<?php

use App\Domain\Organization\Actions\CreateUser;
use App\Domain\Organization\Actions\UpdateUser;
use App\Domain\Organization\Rules\UserRules;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new class extends Component {
    use WithFeedback;

    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = '';

    public bool $is_active = true;

    /**
     * Membership rows; `primaryRow` marks the primary department.
     *
     * @var list<array{department_id: string, position_id: string, is_leader: bool}>
     */
    public array $memberships = [];

    public int $primaryRow = 0;

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $user->load(['activeMemberships', 'roles:id,name']);

            $this->userId = $user->id;
            $this->name = $user->name;
            $this->username = $user->username;
            $this->email = (string) $user->email;
            $this->phone = (string) $user->phone;
            $this->role = (string) $user->roles->first()?->name;
            $this->is_active = $user->is_active;

            $rows = $user->activeMemberships->sortByDesc('is_primary')->values();

            $this->memberships = $rows->map(fn ($membership): array => [
                'department_id' => (string) $membership->department_id,
                'position_id' => (string) $membership->position_id,
                'is_leader' => $membership->is_leader,
            ])->all();

            return;
        }

        $this->authorize('create', User::class);

        $this->role = Role::query()->where('name', 'Staff')->value('name') ?? '';
        $this->addMembership();
    }

    #[Computed]
    public function editing(): ?User
    {
        return $this->userId ? User::findOrFail($this->userId) : null;
    }

    #[Computed]
    public function isSelf(): bool
    {
        return $this->userId !== null && $this->userId === auth()->id();
    }

    /**
     * Active departments plus any already assigned (so existing values still display).
     *
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departmentOptions(): Collection
    {
        $assigned = collect($this->memberships)->pluck('department_id')->filter()->map(fn ($id) => (int) $id);

        return Department::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $assigned))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * @return Collection<int, Position>
     */
    #[Computed]
    public function positionOptions(): Collection
    {
        $assigned = collect($this->memberships)->pluck('position_id')->filter()->map(fn ($id) => (int) $id);

        return Position::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $assigned))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * Only a Super Admin may grant the Super Admin role.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function roleOptions(): Collection
    {
        return Role::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query->where('name', '!=', config('harma.super_admin_role')))
            ->orderBy('name')
            ->pluck('name');
    }

    public function addMembership(): void
    {
        $this->memberships[] = ['department_id' => '', 'position_id' => '', 'is_leader' => false];
    }

    public function removeMembership(int $index): void
    {
        unset($this->memberships[$index]);
        $this->memberships = array_values($this->memberships);

        if ($this->primaryRow === $index || $this->primaryRow >= count($this->memberships)) {
            $this->primaryRow = 0;
        } elseif ($this->primaryRow > $index) {
            $this->primaryRow--;
        }
    }

    public function save(CreateUser $createUser, UpdateUser $updateUser): void
    {
        $user = $this->editing;
        $user ? $this->authorize('update', $user) : $this->authorize('create', User::class);

        $this->username = strtolower(trim($this->username));
        $this->email = strtolower(trim($this->email));
        // Mark the primary row before dropping empty rows, so indexes stay correct.
        $rows = collect($this->memberships)
            ->map(fn (array $row, int $index): array => [
                'department_id' => $row['department_id'],
                'position_id' => ($row['position_id'] ?? '') ?: null,
                'is_leader' => (bool) ($row['is_leader'] ?? false),
                'is_primary' => $index === (int) $this->primaryRow,
            ])
            ->filter(fn (array $row): bool => filled($row['department_id']))
            ->values();

        if ($rows->isNotEmpty() && $rows->doesntContain('is_primary', true)) {
            $rows = $rows->map(fn (array $row, int $index): array => [...$row, 'is_primary' => $index === 0]);
        }

        $this->memberships = $rows->all();
        $this->primaryRow = (int) $rows->search(fn (array $row): bool => $row['is_primary']);

        $validated = $this->validate(UserRules::rules($user));
        $validated['phone'] = $validated['phone'] ?: null;
        $validated['email'] = $validated['email'] ?: null;

        if ($user) {
            unset($validated['is_active']);

            if ($this->isSelf) {
                unset($validated['role']);
            }

            $updateUser->handle($user, $validated, auth()->user());

            $this->notifyAfterRedirect('success', 'User berhasil diperbarui.');
        } else {
            $user = $createUser->handle($validated, auth()->user());

            $this->notifyAfterRedirect('success', 'User berhasil ditambahkan.');
        }

        $this->redirectRoute('admin.users.show', $user, navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->userId ? 'Edit Member' : 'Add Member');
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <x-ui.page-header :title="$userId ? 'Edit Member' : 'Add Member'" :description="$userId ? 'Perbarui data, keanggotaan department, dan role user.' : 'Buat akun internal untuk anggota tim. Tidak ada registrasi publik.'">
        <x-slot:breadcrumbs>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.users.index')" wire:navigate>Members</flux:breadcrumbs.item>
                @if ($this->editing)
                    <flux:breadcrumbs.item :href="route('admin.users.show', $this->editing)" wire:navigate>{{ $this->editing->name }}</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Edit</flux:breadcrumbs.item>
                @else
                    <flux:breadcrumbs.item>Add</flux:breadcrumbs.item>
                @endif
            </flux:breadcrumbs>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <x-ui.form-section title="Basic Information" description="Identitas dan kredensial login.">
            <flux:input wire:model="name" label="Name" badge="Required" autocomplete="off" />
            <flux:input wire:model="username" label="Username" badge="Required" autocomplete="off" autocapitalize="none" spellcheck="false" description="Dipakai untuk login. Huruf kecil, angka, titik, strip, atau garis bawah." />
            <flux:input wire:model="email" type="email" label="Email" autocomplete="off" description="Opsional. Dibutuhkan untuk menerima link reset password." />
            <flux:input wire:model="phone" type="tel" label="Phone" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="password" type="password" :label="$userId ? 'New password' : 'Temporary password'" :badge="$userId ? null : 'Required'" autocomplete="new-password" viewable />
                <flux:input wire:model="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" viewable />
            </div>
            <flux:text size="sm">
                {{ $userId
                    ? 'Kosongkan jika tidak ingin mengganti password. Lebih aman gunakan "Send reset link" di halaman detail user.'
                    : 'Sampaikan password sementara secara aman. User dapat menggantinya di menu Security.' }}
            </flux:text>
        </x-ui.form-section>

        <x-ui.form-section title="Organization" description="User dapat tergabung di beberapa department. Pilih satu sebagai primary department. Leader bersifat kontekstual per department.">
            @error('memberships')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror

            <div class="space-y-3">
                @forelse ($memberships as $index => $membership)
                    <div wire:key="membership-row-{{ $index }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:select wire:model="memberships.{{ $index }}.department_id" label="Department" placeholder="Pilih department">
                                @foreach ($this->departmentOptions as $option)
                                    <flux:select.option :value="$option->id" wire:key="dept-{{ $index }}-{{ $option->id }}">
                                        {{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model="memberships.{{ $index }}.position_id" label="Position" placeholder="Tanpa position">
                                <flux:select.option value="">Tanpa position</flux:select.option>
                                @foreach ($this->positionOptions as $option)
                                    <flux:select.option :value="$option->id" wire:key="pos-{{ $index }}-{{ $option->id }}">
                                        {{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:error name="memberships.{{ $index }}.department_id" />
                        <flux:error name="memberships.{{ $index }}.position_id" />

                        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                                <input type="radio" wire:model="primaryRow" value="{{ $index }}" name="primaryRow" class="size-4 accent-[var(--color-accent)]" />
                                Primary department
                            </label>
                            <flux:checkbox wire:model="memberships.{{ $index }}.is_leader" label="Department leader" />
                            <flux:spacer />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeMembership({{ $index }})">Remove</flux:button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-6 text-center text-sm text-zinc-500 dark:border-zinc-600">
                        User belum tergabung di department mana pun.
                    </div>
                @endforelse
            </div>

            <flux:button size="sm" icon="plus" wire:click="addMembership">Add department</flux:button>
        </x-ui.form-section>

        <x-ui.form-section title="Access" description="Role sistem menentukan permission. Project Manager dan Department Leader bukan role sistem.">
            <flux:select wire:model="role" label="System role" badge="Required" :disabled="$this->isSelf"
                :description="$this->isSelf ? 'Anda tidak dapat mengubah role akun Anda sendiri.' : null">
                @foreach ($this->roleOptions as $roleName)
                    <flux:select.option :value="$roleName" wire:key="role-{{ $loop->index }}">{{ $roleName }}</flux:select.option>
                @endforeach
            </flux:select>

            @unless ($userId)
                <flux:switch wire:model="is_active" label="Active" description="User nonaktif tidak dapat login." />
            @else
                <flux:text size="sm">Status aktif diubah melalui aksi Activate/Deactivate di halaman detail agar tercatat dan sesi user dapat dicabut.</flux:text>
            @endunless
        </x-ui.form-section>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" :href="$this->editing ? route('admin.users.show', $this->editing) : route('admin.users.index')" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary" data-test="save-user-button">
                <span wire:loading.remove wire:target="save">{{ $userId ? 'Save changes' : 'Create member' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
        </div>
    </form>
</div>
