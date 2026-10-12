<?php

namespace Database\Seeders\System;

use App\Domain\Workflow\Actions\EnsureDefaultWorkflows;
use Illuminate\Database\Seeder;

/**
 * Default Project/Task/Ticket workflows for fresh installations (after the
 * status masters exist) and binding of unbound records. Idempotent and never
 * replaces a workflow an administrator created or edited.
 */
class WorkflowSeeder extends Seeder
{
    public function run(EnsureDefaultWorkflows $ensure): void
    {
        $ensure->handle();
    }
}
