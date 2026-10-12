<?php

namespace Database\Seeders\System;

use App\Enums\MasterScope;
use App\Models\Priority;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectRole;
use App\Models\Status;
use Illuminate\Database\Seeder;

/**
 * Minimum project masters the application needs to create projects.
 * Records are created only when their code is missing, so administrator
 * changes (names, colours, order) are never overwritten.
 */
class ProjectMasterSeeder extends Seeder
{
    public function run(): void
    {
        $project = MasterScope::Project->value;

        $statuses = [
            ['DRAFT', 'Draft', 'zinc', 10, ['is_initial' => true]],
            ['PLANNING', 'Planning', 'sky', 20, []],
            ['ACTIVE', 'Active', 'blue', 30, []],
            ['ON_HOLD', 'On Hold', 'amber', 40, []],
            ['COMPLETED', 'Completed', 'green', 50, ['is_terminal' => true, 'counts_as_completed' => true]],
            ['CANCELLED', 'Cancelled', 'red', 60, ['is_terminal' => true]],
        ];

        foreach ($statuses as [$code, $name, $color, $order, $flags]) {
            Status::query()->firstOrCreate(
                ['scope' => $project, 'code' => $code],
                ['name' => $name, 'color' => $color, 'sort_order' => $order, ...$flags],
            );
        }

        foreach ([['LOW', 'Low', 10, 'zinc'], ['NORMAL', 'Normal', 20, 'sky'], ['HIGH', 'High', 30, 'orange'], ['CRITICAL', 'Critical', 40, 'red']] as $index => [$code, $name, $level, $color]) {
            Priority::query()->firstOrCreate(
                ['scope' => $project, 'code' => $code],
                ['name' => $name, 'level' => $level, 'color' => $color, 'sort_order' => ($index + 1) * 10],
            );
        }

        $health = [
            ['ON_TRACK', 'On Track', 'green', 10, ['is_default' => true], 'Project berjalan sesuai rencana.'],
            ['AT_RISK', 'At Risk', 'amber', 20, [], 'Ada potensi keterlambatan atau kendala.'],
            ['CRITICAL', 'Critical', 'red', 30, [], 'Butuh perhatian segera.'],
            ['COMPLETED', 'Completed', 'zinc', 0, ['represents_completion' => true], 'Project sudah selesai.'],
        ];

        foreach ($health as $index => [$code, $name, $color, $level, $flags, $description]) {
            ProjectHealthStatus::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'color' => $color, 'level' => $level, 'sort_order' => ($index + 1) * 10, 'description' => $description, ...$flags],
            );
        }

        foreach ([['DEPT_LEAD', 'Department Lead'], ['DEVELOPER', 'Developer'], ['REVIEWER', 'Reviewer'], ['MEMBER', 'Member'], ['OBSERVER', 'Observer']] as $index => [$code, $name]) {
            ProjectRole::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }
    }
}
