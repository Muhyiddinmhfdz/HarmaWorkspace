<?php

namespace App\Domain\Task\Support;

use App\Domain\Audit\AuditLogger;
use App\Models\Task;

/**
 * Writes task activity (subject = task). Significant events also carry the
 * project id so the project timeline can show them without duplicating rows.
 */
class TaskAudit
{
    /**
     * Events shown on the project activity timeline as well.
     *
     * @var list<string>
     */
    public const array PROJECT_EVENTS = [
        'task.created',
        'task.completed',
        'task.cancelled',
        'task.blocked',
        'task.schedule_changed',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $properties
     */
    public function record(Task $task, string $event, string $description, array $old = [], array $new = [], array $properties = []): void
    {
        $this->audit->record(
            event: $event,
            description: $description,
            subject: $task,
            old: $old,
            new: $new,
            properties: array_filter([
                'task_number' => $task->number,
                'project_id' => $task->project_id,
                ...$properties,
            ], fn ($value) => $value !== null),
        );
    }
}
