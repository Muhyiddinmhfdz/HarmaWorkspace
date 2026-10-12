<?php

/*
|--------------------------------------------------------------------------
| Sidebar Navigation
|--------------------------------------------------------------------------
|
| Only modules that exist are listed; future modules are added in their
| phase. Each item is shown only when the user holds `permission`. Hiding an
| item is not authorization: every route is protected on the backend too.
|
| `active` is a route-name pattern used to highlight the current item.
|
*/

return [
    [
        'heading' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => 'dashboard.view'],
        ],
    ],
    [
        'heading' => 'My Work',
        'items' => [
            ['label' => 'My Tasks', 'icon' => 'check-circle', 'route' => 'tasks.mine', 'active' => 'tasks.mine', 'permission' => ['task.view', 'task.view-all']],
        ],
    ],
    [
        'heading' => 'Projects',
        'items' => [
            ['label' => 'Projects', 'icon' => 'folder', 'route' => 'projects.index', 'active' => 'projects.*', 'permission' => ['project.view', 'project.view-all']],
        ],
    ],
    [
        'heading' => 'Work',
        'items' => [
            ['label' => 'Tasks', 'icon' => 'queue-list', 'route' => 'tasks.index', 'active' => 'tasks.index|tasks.show|tasks.create|tasks.edit', 'permission' => ['task.view', 'task.view-all']],
        ],
    ],
    [
        'heading' => 'Team',
        'items' => [
            ['label' => 'Members', 'icon' => 'users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'permission' => 'user.view'],
            ['label' => 'Departments', 'icon' => 'building-office-2', 'route' => 'admin.departments.index', 'active' => 'admin.departments.*', 'permission' => 'department.view'],
        ],
    ],
    [
        'heading' => 'Master Data',
        'items' => [
            ['label' => 'Master Data', 'icon' => 'circle-stack', 'route' => 'master-data.index', 'active' => 'master-data.*', 'permission' => 'master-data.view'],
            ['label' => 'Clients', 'icon' => 'briefcase', 'route' => 'clients.index', 'active' => 'clients.*', 'permission' => 'client.view'],
        ],
    ],
    [
        'heading' => 'Administration',
        'items' => [
            ['label' => 'Role & Permission', 'icon' => 'shield-check', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*|admin.permissions.*', 'permission' => 'role.view'],
            ['label' => 'System Settings', 'icon' => 'cog-6-tooth', 'route' => 'settings.index', 'active' => 'settings.*', 'permission' => 'settings.view'],
            ['label' => 'Audit Log', 'icon' => 'clipboard-document-list', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*', 'permission' => 'audit.view'],
        ],
    ],
];
