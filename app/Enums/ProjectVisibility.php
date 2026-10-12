<?php

namespace App\Enums;

/**
 * Who may VIEW a project. Independent from the client relation: a project
 * without a client is an "Internal Project", not a company-wide one.
 * Visibility never grants update or management rights.
 */
enum ProjectVisibility: string
{
    /** Default: project team (owner, PM, members) and leaders of involved departments. */
    case Restricted = 'restricted';

    /** Restricted + every active member of an involved department (lead or supporting). */
    case Department = 'department';

    /** Every active user holding project.view. Requires project.manage-visibility to set. */
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Restricted => 'Restricted',
            self::Department => 'Department',
            self::Company => 'Company',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Restricted => 'Hanya tim Project dan pihak yang memiliki akses terkait.',
            self::Department => 'Dapat dilihat oleh anggota Department yang terlibat dalam Project.',
            self::Company => 'Dapat dilihat oleh seluruh pengguna internal yang memiliki akses Project.',
        };
    }

    public function requiresAdministrativePermission(): bool
    {
        return $this === self::Company;
    }
}
