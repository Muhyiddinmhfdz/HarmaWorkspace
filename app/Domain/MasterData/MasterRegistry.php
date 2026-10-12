<?php

namespace App\Domain\MasterData;

use App\Enums\MasterScope;
use App\Models\Priority;
use App\Models\ProjectCategory;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectRole;
use App\Models\Status;
use App\Models\TaskCategory;
use App\Models\TaskType;

/**
 * Masters managed through the generic page `/master-data/{type}`.
 * Organization masters (department, position) and clients keep their own
 * pages because they carry extra behaviour.
 */
class MasterRegistry
{
    /**
     * @return array<string, MasterDefinition>
     */
    public static function all(): array
    {
        $definitions = [
            new MasterDefinition(
                key: 'project-categories',
                label: 'Project Categories',
                singular: 'Project Category',
                description: 'Jenis project, misalnya Development atau Maintenance.',
                icon: 'tag',
                model: ProjectCategory::class,
            ),
            new MasterDefinition(
                key: 'project-statuses',
                label: 'Project Statuses',
                singular: 'Project Status',
                description: 'Tahapan lifecycle project. Perilaku sistem mengikuti flag, bukan nama.',
                icon: 'flag',
                model: Status::class,
                scope: MasterScope::Project,
                fields: [
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color'],
                    ['name' => 'is_initial', 'label' => 'Initial status', 'type' => 'boolean', 'help' => 'Dipakai otomatis untuk project baru. Hanya satu status.'],
                    ['name' => 'is_terminal', 'label' => 'Terminal status', 'type' => 'boolean', 'help' => 'Project dianggap berakhir (selesai atau batal).'],
                    ['name' => 'counts_as_completed', 'label' => 'Counts as completed', 'type' => 'boolean', 'help' => 'Dipakai saat project diselesaikan.'],
                ],
                exclusiveFlags: ['is_initial'],
            ),
            new MasterDefinition(
                key: 'project-priorities',
                label: 'Project Priorities',
                singular: 'Project Priority',
                description: 'Tingkat urgensi project. Level lebih tinggi = lebih mendesak.',
                icon: 'signal',
                model: Priority::class,
                scope: MasterScope::Project,
                fields: [
                    ['name' => 'level', 'label' => 'Level', 'type' => 'number', 'help' => 'Contoh: 10 Low, 20 Normal, 30 High, 40 Critical.'],
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color'],
                ],
            ),
            new MasterDefinition(
                key: 'project-roles',
                label: 'Project Roles',
                singular: 'Project Role',
                description: 'Peran anggota di dalam project (bukan role sistem).',
                icon: 'user-group',
                model: ProjectRole::class,
            ),
            new MasterDefinition(
                key: 'project-health',
                label: 'Project Health',
                singular: 'Project Health',
                description: 'Indikator kondisi project. Level lebih tinggi = kondisi lebih buruk.',
                icon: 'heart',
                model: ProjectHealthStatus::class,
                fields: [
                    ['name' => 'level', 'label' => 'Severity level', 'type' => 'number', 'help' => 'Contoh: 10 On Track, 20 At Risk, 30 Critical.'],
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color'],
                    ['name' => 'is_default', 'label' => 'Default for new projects', 'type' => 'boolean', 'help' => 'Hanya satu health.'],
                    ['name' => 'represents_completion', 'label' => 'Used when project is completed', 'type' => 'boolean', 'help' => 'Hanya satu health.'],
                ],
                exclusiveFlags: ['is_default', 'represents_completion'],
            ),
            new MasterDefinition(
                key: 'task-types',
                label: 'Task Types',
                singular: 'Task Type',
                description: 'Jenis pekerjaan, misalnya Feature, Bug Fix, atau Maintenance.',
                icon: 'squares-2x2',
                model: TaskType::class,
                fields: [['name' => 'color', 'label' => 'Color', 'type' => 'color']],
                group: 'Task',
            ),
            new MasterDefinition(
                key: 'task-categories',
                label: 'Task Categories',
                singular: 'Task Category',
                description: 'Kelompok pekerjaan, misalnya Development atau Quality Assurance.',
                icon: 'rectangle-stack',
                model: TaskCategory::class,
                fields: [['name' => 'color', 'label' => 'Color', 'type' => 'color']],
                group: 'Task',
            ),
            new MasterDefinition(
                key: 'task-statuses',
                label: 'Task Statuses',
                singular: 'Task Status',
                description: 'Tahapan kerja task. Perilaku sistem mengikuti flag, bukan nama.',
                icon: 'flag',
                model: Status::class,
                scope: MasterScope::Task,
                fields: [
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color'],
                    ['name' => 'is_initial', 'label' => 'Initial status', 'type' => 'boolean', 'help' => 'Default untuk task baru. Hanya satu status.'],
                    ['name' => 'counts_as_started', 'label' => 'Work started', 'type' => 'boolean', 'help' => 'Mengisi tanggal mulai aktual saat pertama kali dipakai.'],
                    ['name' => 'is_blocked', 'label' => 'Blocked', 'type' => 'boolean', 'help' => 'Wajib alasan; dihitung sebagai task blocked.'],
                    ['name' => 'is_terminal', 'label' => 'Terminal status', 'type' => 'boolean', 'help' => 'Task berakhir (selesai atau batal).'],
                    ['name' => 'counts_as_completed', 'label' => 'Counts as completed', 'type' => 'boolean', 'help' => 'Progress 100% dan dihitung selesai.'],
                ],
                exclusiveFlags: ['is_initial'],
                group: 'Task',
            ),
            new MasterDefinition(
                key: 'task-priorities',
                label: 'Task Priorities',
                singular: 'Task Priority',
                description: 'Tingkat urgensi task. Level lebih tinggi = lebih mendesak.',
                icon: 'signal',
                model: Priority::class,
                scope: MasterScope::Task,
                fields: [
                    ['name' => 'level', 'label' => 'Level', 'type' => 'number', 'help' => 'Contoh: 10 Low, 20 Normal, 30 High, 40 Critical.'],
                    ['name' => 'color', 'label' => 'Color', 'type' => 'color'],
                ],
                group: 'Task',
            ),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }

    public static function find(string $key): ?MasterDefinition
    {
        return self::all()[$key] ?? null;
    }
}
