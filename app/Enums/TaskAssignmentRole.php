<?php

namespace App\Enums;

enum TaskAssignmentRole: string
{
    /** The one user primarily responsible for the task. */
    case Pic = 'pic';

    /** Users helping the PIC. */
    case Collaborator = 'collaborator';
}
