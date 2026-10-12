@php
    $user = auth()->user();
    $navigation = app(\App\Domain\System\Navigation\Navigation::class)->for($user);
    $subtitle = app(\App\Domain\System\Settings\Settings::class)->get(\App\Domain\System\Settings\SettingKey::AppSubtitle);
    // Global create keeps only the everyday actions; administrative records
    // are created from their own pages.
    $createItems = array_values(array_filter([
        $user->can('project.create') ? ['label' => 'New Project', 'icon' => 'folder-plus', 'url' => route('projects.create')] : null,
        $user->can('create', \App\Models\Task::class) ? ['label' => 'New Task', 'icon' => 'plus-circle', 'url' => route('tasks.create')] : null,
        $user->can('create', \App\Models\Ticket::class) ? ['label' => 'New Report', 'icon' => 'chat-bubble-left-right', 'url' => route('tickets.create')] : null,
    ]));
    // Mobile bottom bar: the four daily destinations plus the full menu.
    $mobileItems = array_values(array_filter([
        $user->can('dashboard.view') ? ['label' => 'Dashboard', 'icon' => 'home', 'url' => route('dashboard'), 'current' => request()->routeIs('dashboard')] : null,
        $user->canAny(['project.view', 'project.view-all']) ? ['label' => 'Projects', 'icon' => 'folder', 'url' => route('projects.index'), 'current' => request()->routeIs('projects.*')] : null,
        $user->canAny(['task.view', 'task.view-all']) ? ['label' => 'My Tasks', 'icon' => 'check-circle', 'url' => route('tasks.mine'), 'current' => request()->routeIs('tasks.*')] : null,
        $user->canAny(['ticket.view', 'ticket.view-all']) ? ['label' => 'Reports', 'icon' => 'lifebuoy', 'url' => route('tickets.mine'), 'current' => request()->routeIs('tickets.*')] : null,
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

        <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="Mobile navigation">
            <div class="mx-auto flex max-w-md items-stretch justify-around">
                @foreach ($mobileItems as $mobileItem)
                    <a href="{{ $mobileItem['url'] }}" wire:navigate @if ($mobileItem['current']) aria-current="page" @endif
                       @class(['flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] font-medium', 'text-zinc-900' => $mobileItem['current'], 'text-zinc-500' => ! $mobileItem['current']])>
                        <flux:icon :icon="$mobileItem['icon']" :variant="$mobileItem['current'] ? 'solid' : 'outline'" class="size-5" />
                        {{ $mobileItem['label'] }}
                    </a>
                @endforeach
                <button type="button" x-data x-on:click="$dispatch('flux-sidebar-toggle')" class="flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] font-medium text-zinc-500" aria-label="Open menu">
                    <flux:icon.bars-3 class="size-5" />
                    More
                </button>
            </div>
        </nav>

        @php
            $flash = session('notify') ?? ($errors->has('business') ? ['type' => 'error', 'message' => $errors->first('business')] : null);
        @endphp
        @if ($flash)
            <div hidden data-flash-notify="{{ json_encode($flash) }}"></div>
        @endif

        @fluxScripts
    </body>
</html>
