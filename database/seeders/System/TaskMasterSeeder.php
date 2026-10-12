<?php

namespace Database\Seeders\System;

use App\Enums\MasterScope;
use App\Models\Priority;
use App\Models\Status;
use Illuminate\Database\Seeder;

/**
 * Task statuses and priorities the task lifecycle needs. Created only when
 * the code is missing, so administrator changes are never overwritten.
 */
class TaskMasterSeeder extends Seeder
{
    public function run(): void
    {
        $task = MasterScope::Task->value;

        $statuses = [
            ['BACKLOG', 'Backlog', 'zinc', ['is_initial' => true]],
            ['TODO', 'To Do', 'sky', []],
            ['IN_PROGRESS', 'In Progress', 'blue', ['counts_as_started' => true]],
            ['BLOCKED', 'Blocked', 'red', ['counts_as_started' => true, 'is_blocked' => true]],
            ['IN_REVIEW', 'In Review', 'violet', ['counts_as_started' => true]],
            ['REVISION', 'Revision', 'amber', ['counts_as_started' => true]],
            ['DONE', 'Done', 'green', ['is_terminal' => true, 'counts_as_completed' => true]],
            ['CANCELLED', 'Cancelled', 'zinc', ['is_terminal' => true]],
        ];

        foreach ($statuses as $index => [$code, $name, $color, $flags]) {
            Status::query()->firstOrCreate(
                ['scope' => $task, 'code' => $code],
                ['name' => $name, 'color' => $color, 'sort_order' => ($index + 1) * 10, ...$flags],
            );
        }

        foreach ([['LOW', 'Low', 10, 'zinc'], ['NORMAL', 'Normal', 20, 'sky'], ['HIGH', 'High', 30, 'orange'], ['CRITICAL', 'Critical', 40, 'red']] as $index => [$code, $name, $level, $color]) {
            Priority::query()->firstOrCreate(
                ['scope' => $task, 'code' => $code],
                ['name' => $name, 'level' => $level, 'color' => $color, 'sort_order' => ($index + 1) * 10],
            );
        }
    }
}
