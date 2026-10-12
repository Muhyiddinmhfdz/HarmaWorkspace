{{-- Navigation between the Role & Permission pages (Flux tabs are a Pro component). --}}
<nav class="mb-6 flex gap-1 border-b border-zinc-200 dark:border-zinc-700" aria-label="Role & Permission">
    @foreach ([
        ['Roles', 'admin.roles.index', 'admin.roles.*', 'role.view'],
        ['Permissions', 'admin.permissions.index', 'admin.permissions.*', 'permission.view'],
    ] as [$label, $route, $pattern, $permission])
        @can($permission)
            <a href="{{ route($route) }}" wire:navigate
               @class([
                   '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                   'border-accent text-zinc-900 dark:text-white' => request()->routeIs($pattern),
                   'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => ! request()->routeIs($pattern),
               ])
               @if (request()->routeIs($pattern)) aria-current="page" @endif>
                {{ $label }}
            </a>
        @endcan
    @endforeach
</nav>
