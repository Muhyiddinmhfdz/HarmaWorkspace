<?php

namespace App\Enums;

/**
 * How the approvers of a step are found. Config shapes (validated, never code):
 *
 * - specific_user: {user_id: int}
 * - department_leader: {department_id?: int} — default: the record's department
 * - project_manager / project_owner: {} — of the related project
 * - project_role: {project_role_id: int} — active members of the related project
 * - requester / creator: {} — of the record
 * - permission: {permission: string} — users holding it who can see the record
 */
enum ApproverType: string
{
    case SpecificUser = 'specific_user';
    case DepartmentLeader = 'department_leader';
    case ProjectManager = 'project_manager';
    case ProjectOwner = 'project_owner';
    case ProjectRole = 'project_role';
    case Requester = 'requester';
    case Creator = 'creator';
    case Permission = 'permission';

    public function label(): string
    {
        return match ($this) {
            self::SpecificUser => 'Specific user',
            self::DepartmentLeader => 'Department leader',
            self::ProjectManager => 'Project manager',
            self::ProjectOwner => 'Project owner',
            self::ProjectRole => 'Project role',
            self::Requester => 'Requester',
            self::Creator => 'Creator',
            self::Permission => 'Users with permission',
        };
    }
}
