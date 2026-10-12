<?php

namespace App\Domain\Access;

/**
 * The catalogue of permissions known to HarmaWorkspace.
 *
 * Permissions are code-defined because a permission only means something when
 * code checks it. Administrators assign them to roles; they do not invent new
 * ones. The SystemSeeder syncs this list to the database.
 *
 * Naming: `<module>.<action>`.
 */
class PermissionRegistry
{
    /**
     * Permissions introduced in Phase 2, granted to the existing default roles
     * once by a data migration (roles created later receive them via defaultRoles()).
     *
     * @var array<string, list<string>>
     */
    public const array PHASE_2_DEFAULTS = [
        'Management' => [
            'project.view',
            'project.view-all',
            'project.create',
            'project.update',
            'project.manage-departments',
            'project.manage-members',
            'project.manage-schedule',
            'project.manage-milestones',
            'project.manage-progress',
            'project.complete',
            'project.archive',
            'project.manage-visibility',
        ],
        'Staff' => [
            'project.view',
        ],
    ];

    /**
     * Permissions grouped by module, with an Indonesian description.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'permissions' => [
                    'dashboard.view' => 'Membuka dashboard',
                ],
            ],
            'user' => [
                'label' => 'Users',
                'permissions' => [
                    'user.view' => 'Melihat daftar dan detail user',
                    'user.create' => 'Membuat user baru',
                    'user.update' => 'Mengubah data, department, dan role user',
                    'user.activate' => 'Mengaktifkan user',
                    'user.deactivate' => 'Menonaktifkan user',
                ],
            ],
            'department' => [
                'label' => 'Departments',
                'permissions' => [
                    'department.view' => 'Melihat department dan anggotanya',
                    'department.create' => 'Membuat department',
                    'department.update' => 'Mengubah department',
                    'department.activate' => 'Mengaktifkan department',
                    'department.deactivate' => 'Menonaktifkan department',
                ],
            ],
            'position' => [
                'label' => 'Positions',
                'permissions' => [
                    'position.view' => 'Melihat position',
                    'position.create' => 'Membuat position',
                    'position.update' => 'Mengubah position',
                    'position.activate' => 'Mengaktifkan position',
                    'position.deactivate' => 'Menonaktifkan position',
                ],
            ],
            'client' => [
                'label' => 'Clients',
                'permissions' => [
                    'client.view' => 'Melihat client',
                    'client.create' => 'Membuat client',
                    'client.update' => 'Mengubah client',
                    'client.activate' => 'Mengaktifkan client',
                    'client.deactivate' => 'Menonaktifkan client',
                ],
            ],
            'master-data' => [
                'label' => 'Master Data',
                'permissions' => [
                    'master-data.view' => 'Membuka Master Data Center',
                    'master-data.manage' => 'Mengelola master Project (category, status, priority, role, health)',
                ],
            ],
            'project' => [
                'label' => 'Projects',
                'permissions' => [
                    'project.view' => 'Melihat project yang relevan (owner, PM, anggota, department yang dipimpin, atau internal)',
                    'project.view-all' => 'Melihat seluruh project',
                    'project.create' => 'Membuat project baru',
                    'project.update' => 'Mengubah informasi, owner, PM, dan status project',
                    'project.manage-departments' => 'Mengatur lead & supporting department project',
                    'project.manage-members' => 'Mengatur anggota dan project role',
                    'project.manage-schedule' => 'Mengubah jadwal project',
                    'project.manage-milestones' => 'Mengelola milestone',
                    'project.manage-progress' => 'Mengubah progress dan health project',
                    'project.complete' => 'Menyelesaikan atau membatalkan project',
                    'project.archive' => 'Mengarsipkan project yang sudah selesai',
                    'project.manage-visibility' => 'Membuka project untuk seluruh perusahaan (visibility Company)',
                ],
            ],
            'role' => [
                'label' => 'Roles',
                'permissions' => [
                    'role.view' => 'Melihat role dan permission-nya',
                    'role.manage' => 'Membuat, mengubah, dan menghapus role',
                ],
            ],
            'permission' => [
                'label' => 'Permissions',
                'permissions' => [
                    'permission.view' => 'Melihat katalog permission',
                ],
            ],
            'audit' => [
                'label' => 'Audit Log',
                'permissions' => [
                    'audit.view' => 'Melihat audit log',
                ],
            ],
            'settings' => [
                'label' => 'System Settings',
                'permissions' => [
                    'settings.view' => 'Melihat system settings',
                    'settings.manage' => 'Mengubah system settings',
                ],
            ],
        ];
    }

    /**
     * All permission names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(
            fn (array $group): array => array_keys($group['permissions']),
            array_values(self::groups()),
        ));
    }

    /**
     * Description of a single permission.
     */
    public static function describe(string $permission): ?string
    {
        foreach (self::groups() as $group) {
            if (isset($group['permissions'][$permission])) {
                return $group['permissions'][$permission];
            }
        }

        return null;
    }

    /**
     * Default permissions for the non-super-admin system roles. Applied only
     * when the role is first created; administrators may change them later.
     * The Super Admin role always receives every permission.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        return [
            'Management' => [
                'dashboard.view',
                'user.view',
                'department.view',
                'position.view',
                'client.view',
                'client.create',
                'client.update',
                'client.activate',
                'client.deactivate',
                'master-data.view',
                'role.view',
                'permission.view',
                'audit.view',
                'settings.view',
                ...self::PHASE_2_DEFAULTS['Management'],
            ],
            'Staff' => [
                'dashboard.view',
                'department.view',
                'client.view',
                ...self::PHASE_2_DEFAULTS['Staff'],
            ],
        ];
    }
}
