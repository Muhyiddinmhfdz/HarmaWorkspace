@php
    $user = auth()->user();
    $navigation = app(\App\Domain\System\Navigation\Navigation::class)->for($user);
    $subtitle = app(\App\Domain\System\Settings\Settings::class)->get(\App\Domain\System\Settings\SettingKey::AppSubtitle);
    $createItems = array_values(array_filter([
        $user->can('user.create') ? ['label' => 'New Member', 'icon' => 'user-plus', 'url' => route('admin.users.create')] : null,
        $user->can('department.create') ? ['label' => 'New Department', 'icon' => 'building-office-2', 'url' => route('admin.departments.index', ['create' => 1])] : null,
        $user->can('client.create') ? ['label' => 'New Client', 'icon' => 'briefcase', 'url' => route('clients.index', ['create' => 1])] : null,
    ]));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-800 antialiased">
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($navigation as $group)
                    <flux:sidebar.group :heading="$group['heading']" class="grid">
                        @foreach ($group['items'] as $item)
                            <flux:sidebar.item :icon="$item['icon']" :href="$item['url']" :current="$item['current']" wire:navigate>
                                {{ $item['label'] }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:text size="sm" class="px-2 text-xs in-data-flux-sidebar-collapsed-desktop:hidden">{{ $subtitle }}</flux:text>
        </flux:sidebar>

        <flux:header sticky class="gap-2 border-b border-zinc-200 bg-white/95 backdrop-blur">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <livewire:global-search />

            <flux:spacer />

            @if ($createItems !== [])
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="primary" icon="plus" data-test="quick-create-button">
                        <span class="max-sm:sr-only">Create</span>
                    </flux:button>

                    <flux:menu>
                        @foreach ($createItems as $createItem)
                            <flux:menu.item :icon="$createItem['icon']" :href="$createItem['url']" wire:navigate>
                                {{ $createItem['label'] }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif

            <flux:dropdown position="bottom" align="end">
                <flux:tooltip content="Notifications" position="bottom">
                    <flux:button size="sm" variant="ghost" icon="bell" aria-label="Notifications" />
                </flux:tooltip>

                <flux:menu class="w-80">
                    <div class="flex flex-col items-center px-4 py-8 text-center">
                        <flux:icon.bell-slash class="mb-3 size-6 text-zinc-400" />
                        <flux:heading>Belum ada notifikasi</flux:heading>
                        <flux:text class="mt-1">Notifikasi pekerjaan akan muncul di sini setelah modul Project, Task, dan Ticket aktif.</flux:text>
                    </div>
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown position="bottom" align="end">
                <flux:profile :initials="$user->initials()" icon-trailing="chevron-down" data-test="profile-menu-button" />

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar :name="$user->name" :initials="$user->initials()" />
                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                            <flux:text class="truncate">{{ '@'.$user->username }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>My Profile</flux:menu.item>
                    <flux:menu.item :href="route('profile.security')" icon="key" wire:navigate>Security</flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                            Log out
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @php
            $flash = session('notify') ?? ($errors->has('business') ? ['type' => 'error', 'message' => $errors->first('business')] : null);
        @endphp
        @if ($flash)
            <div hidden data-flash-notify="{{ json_encode($flash) }}"></div>
        @endif

        @fluxScripts
    </body>
</html>
