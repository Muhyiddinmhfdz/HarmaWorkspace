<?php

use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
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

    Route::livewire('settings', 'pages::settings.index')
        ->middleware('can:settings.view')
        ->name('settings.index');
});
