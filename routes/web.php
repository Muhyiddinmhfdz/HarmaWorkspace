<?php

use App\Http\Controllers\TicketAttachmentController;
use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Every internal page requires authentication. Route-level `can:` middleware
| is the first gate; Livewire pages authorize again in mount() and in every
| action, because Livewire actions can be called directly.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')
        ->middleware('can:dashboard.view')
        ->name('dashboard');

    // My Profile (self-service)
    Route::livewire('profile', 'pages::profile.profile')->name('profile.edit');
    Route::livewire('profile/security', 'pages::profile.security')
        ->middleware('password.confirm')
        ->name('profile.security');

    // Organization & access administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('users', 'pages::admin.users.index')
            ->middleware('can:viewAny,'.User::class)
            ->name('users.index');
        Route::livewire('users/create', 'pages::admin.users.form')
            ->middleware('can:create,'.User::class)
            ->name('users.create');
        Route::livewire('users/{user}', 'pages::admin.users.show')
            ->middleware('can:view,user')
            ->name('users.show');
        Route::livewire('users/{user}/edit', 'pages::admin.users.form')
            ->middleware('can:update,user')
            ->name('users.edit');

        Route::livewire('departments', 'pages::admin.departments.index')
            ->middleware('can:viewAny,'.Department::class)
            ->name('departments.index');
        Route::livewire('departments/{department}', 'pages::admin.departments.show')
            ->middleware('can:view,department')
            ->name('departments.show');

        Route::livewire('positions', 'pages::admin.positions.index')
            ->middleware('can:viewAny,'.Position::class)
            ->name('positions.index');

        Route::livewire('roles', 'pages::admin.roles.index')
            ->middleware('can:viewAny,'.Role::class)
            ->name('roles.index');
        Route::livewire('roles/create', 'pages::admin.roles.form')
            ->middleware('can:create,'.Role::class)
            ->name('roles.create');
        Route::livewire('roles/{role}/edit', 'pages::admin.roles.form')
            ->middleware('can:update,role')
            ->name('roles.edit');

        Route::livewire('permissions', 'pages::admin.permissions.index')
            ->middleware('can:permission.view')
            ->name('permissions.index');

        Route::livewire('audit', 'pages::admin.audit.index')
            ->middleware('can:audit.view')
            ->name('audit.index');
    });

    Route::livewire('clients', 'pages::clients.index')
        ->middleware('can:viewAny,'.Client::class)
        ->name('clients.index');
    Route::livewire('clients/{client}', 'pages::clients.show')
        ->middleware('can:view,client')
        ->name('clients.show');

    Route::livewire('master-data', 'pages::master-data.index')
        ->middleware('can:master-data.view')
        ->name('master-data.index');
    Route::livewire('master-data/{type}', 'pages::master-data.manage')
        ->middleware('can:master-data.view')
        ->name('master-data.manage');

    // Projects
    Route::livewire('projects', 'pages::projects.index')
        ->middleware('can:viewAny,'.Project::class)
        ->name('projects.index');
    Route::livewire('projects/create', 'pages::projects.create')
        ->middleware('can:create,'.Project::class)
        ->name('projects.create');
    Route::livewire('projects/{project}', 'pages::projects.show')
        ->middleware('can:view,project')
        ->name('projects.show');
    Route::livewire('projects/{project}/edit', 'pages::projects.edit')
        ->middleware('can:update,project')
        ->name('projects.edit');

    // Tasks
    Route::livewire('my-tasks', 'pages::tasks.mine')
        ->middleware('can:viewAny,'.Task::class)
        ->name('tasks.mine');
    Route::livewire('tasks', 'pages::tasks.index')
        ->middleware('can:viewAny,'.Task::class)
        ->name('tasks.index');
    Route::livewire('tasks/create', 'pages::tasks.create')
        ->middleware('can:viewAny,'.Task::class)
        ->name('tasks.create');
    Route::livewire('tasks/{task}', 'pages::tasks.show')
        ->middleware('can:view,task')
        ->name('tasks.show');
    Route::livewire('tasks/{task}/edit', 'pages::tasks.edit')
        ->middleware('can:update,task')
        ->name('tasks.edit');

    // Tickets & Department Inbox
    Route::livewire('my-tickets', 'pages::tickets.mine')
        ->middleware('can:viewAny,'.Ticket::class)
        ->name('tickets.mine');
    Route::livewire('department-inbox', 'pages::tickets.inbox')
        ->middleware('can:viewAny,'.Ticket::class)
        ->name('tickets.inbox');
    Route::livewire('tickets', 'pages::tickets.index')
        ->middleware('can:viewAny,'.Ticket::class)
        ->name('tickets.index');
    Route::livewire('tickets/create', 'pages::tickets.create')
        ->middleware('can:create,'.Ticket::class)
        ->name('tickets.create');
    Route::livewire('tickets/{ticket}', 'pages::tickets.show')
        ->middleware('can:view,ticket')
        ->name('tickets.show');
    Route::livewire('tickets/{ticket}/edit', 'pages::tickets.edit')
        ->middleware('can:update,ticket')
        ->name('tickets.edit');
    Route::get('tickets/{ticket}/attachments/{attachment}', TicketAttachmentController::class)
        ->middleware('can:view,ticket')
        ->name('tickets.attachments.download');

    Route::livewire('settings', 'pages::settings.index')
        ->middleware('can:settings.view')
        ->name('settings.index');
});
