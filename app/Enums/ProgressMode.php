<?php

namespace App\Enums;

enum ProgressMode: string
{
    /** Progress entered by the project manager (Phase 2). */
    case Manual = 'manual';

    /** Progress derived from weighted tasks (Phase 3). */
    case TaskWeighted = 'task_weighted';
}
