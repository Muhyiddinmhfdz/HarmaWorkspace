<?php

namespace App\Enums;

/**
 * Domain a shared master (status, priority) belongs to. Technical constant:
 * code relies on it, administrators cannot change it.
 */
enum MasterScope: string
{
    case Project = 'project';
    case Task = 'task';
    case Ticket = 'ticket';
}
