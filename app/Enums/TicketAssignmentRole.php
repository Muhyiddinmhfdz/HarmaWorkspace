<?php

namespace App\Enums;

enum TicketAssignmentRole: string
{
    /** The one user primarily responsible for the ticket. */
    case Pic = 'pic';

    /** Users of the current department helping the PIC. */
    case Collaborator = 'collaborator';
}
