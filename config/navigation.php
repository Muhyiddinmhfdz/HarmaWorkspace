<?php

/*
|--------------------------------------------------------------------------
| Sidebar Navigation
|--------------------------------------------------------------------------
|
| Operational work first (Project → Task → Report), administration last.
| Only modules that exist are listed. Each item is shown only when the user
| holds one of `permission`; `when` adds a contextual condition resolved by
| App\Domain\System\Navigation\Navigation (e.g. department membership).
| Groups without visible items are not rendered.
|
| Hiding an item is not authorization: every route is protected on the
| backend too. Directory pages (Departments, Clients) stay reachable for
| read-only users through links, but only appear in the sidebar for users
| who administer them (Phase 6, simplicity first).
|
| `active` is a route-name pattern used to highlight the current item.
|
*/

return [
    [
        'heading' => null,
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => 'dashboard.view'],
        ],
    ],
    [
        'heading' => 'Projects',
        'items' => [
            ['label' => 'Projects', 'icon' => 'folder', 'route' => 'projects.index', 'active' => 'projects.*', 'permission' => ['project.view', 'project.view-all']],
            ['label' => 'All Tasks', 'icon' => 'queue-list', 'route' => 'tasks.index', 'active' => 'tasks.index', 'permission' => 'task.view-all'],
        ],
    ],
    [
        'heading' => 'My Work',
        'items' => [
            ['label' => 'My Tasks', 'icon' => 'check-circle', 'route' => 'tasks.mine', 'active' => 'tasks.mine', 'permission' => ['task.view', 'task.view-all']],
            ['label' => 'My Reports', 'icon' => 'chat-bubble-left-right', 'route' => 'tickets.mine', 'active' => 'tickets.mine', 'permission' => ['ticket.view', 'ticket.view-all']],
            ['label' => 'My Approvals', 'icon' => 'check-badge', 'route' => 'approvals.mine', 'active' => 'approvals.*', 'permission' => ['approval.view', 'approval.view-all']],
        ],
    ],
    [
        'heading' => 'Reports',
        'items' => [
            ['label' => 'Reports', 'icon' => 'lifebuoy', 'route' => 'tickets.index', 'active' => 'tickets.index|tickets.show|tickets.create|tickets.edit', 'permission' => ['ticket.view', 'ticket.view-all']],
            ['label' => 'Department Inbox', 'icon' => 'inbox-stack', 'route' => 'tickets.inbox', 'active' => 'tickets.inbox', 'permission' => ['ticket.view', 'ticket.view-all'], 'when' => 'department-inbox'],
        ],
    ],
    [
        'heading' => 'Administration',
        'items' => [
            ['label' => 'Members', 'icon' => 'users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'permission' => 'user.view'],
            ['label' => 'Departments', 'icon' => 'building-office-2', 'route' => 'admin.departments.index', 'active' => 'admin.departments.*|admin.positions.*', 'permission' => ['user.view', 'department.create', 'department.update']],
            ['label' => 'Clients', 'icon' => 'briefcase', 'route' => 'clients.index', 'active' => 'clients.*', 'permission' => ['client.create', 'client.update']],
            ['label' => 'Master Data', 'icon' => 'circle-stack', 'route' => 'master-data.index', 'active' => 'master-data.*', 'permission' => 'master-data.view'],
            ['label' => 'Workflows', 'icon' => 'arrow-path-rounded-square', 'route' => 'admin.workflows.index', 'active' => 'admin.workflows.*', 'permission' => ['workflow.view', 'workflow.manage']],
            ['label' => 'Approval Templates', 'icon' => 'clipboard-document-check', 'route' => 'admin.approval-templates.index', 'active' => 'admin.approval-templates.*', 'permission' => 'approval.manage-templates'],
            ['label' => 'Role & Permission', 'icon' => 'shield-check', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*|admin.permissions.*', 'permission' => 'role.view'],
            ['label' => 'System Settings', 'icon' => 'cog-6-tooth', 'route' => 'settings.index', 'active' => 'settings.*', 'permission' => 'settings.view'],
            ['label' => 'Audit Log', 'icon' => 'clipboard-document-list', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*', 'permission' => 'audit.view'],
        ],
    ],
];
