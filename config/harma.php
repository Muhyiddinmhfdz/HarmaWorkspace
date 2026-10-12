<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin Role
    |--------------------------------------------------------------------------
    |
    | The single system role that bypasses permission checks (Gate::before).
    | This is the only place the role name is referenced by code.
    |
    */

    'super_admin_role' => 'Super Admin',

    /*
    |--------------------------------------------------------------------------
    | Initial Super Admin
    |--------------------------------------------------------------------------
    |
    | Read by the SystemSeeder to create the first account. The defaults are for
    | first-time setup only: change the password right after the first login,
    | and always override them through the environment in production.
    |
    */

    'super_admin' => [
        'name' => env('HARMA_SUPER_ADMIN_NAME', 'Super Admin'),
        'username' => env('HARMA_SUPER_ADMIN_USERNAME', 'superadmin'),
        'email' => env('HARMA_SUPER_ADMIN_EMAIL'),
        'password' => env('HARMA_SUPER_ADMIN_PASSWORD', '123123123'),
    ],

];
