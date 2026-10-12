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

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files are stored on a private disk under generated names and are only
    | served through authorized download routes. The type is checked from the
    | file content (MIME) and the extension must match the same whitelist, so
    | renamed executables are rejected.
    |
    */

    'attachments' => [
        'disk' => env('HARMA_ATTACHMENT_DISK', 'local'),
        'max_kb' => (int) env('HARMA_ATTACHMENT_MAX_KB', 10240),
        'max_per_upload' => 5,
        'types' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'txt' => ['text/plain'],
        ],
    ],

];
