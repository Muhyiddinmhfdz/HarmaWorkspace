<?php

namespace App\Domain\Task\Support;

use App\Models\Task;

/**
 * The single definition of a task's effective weight (ADR-045):
 *
 *   manual weight override  → used when set;
 *   estimated hours (> 0)   → used otherwise;
 *   fallback                → 1.
 *
 * PHP and SQL share this class so the formula exists exactly once.
 */
final class TaskWeight
{
    public const float FALLBACK = 1.0;

    /**
     * SQL expression for the effective weight of a row in `tasks`
     * (kept a literal so it is safe for raw queries; mirrors FALLBACK).
     */
    public const string SQL = 'COALESCE(tasks.weight, NULLIF(tasks.estimated_hours, 0), 1.0)';

    public static function of(Task $task): float
    {
        if ($task->weight !== null && (float) $task->weight > 0) {
            return (float) $task->weight;
        }

        if ($task->estimated_hours !== null && (float) $task->estimated_hours > 0) {
            return (float) $task->estimated_hours;
        }

        return self::FALLBACK;
    }

    public static function sourceLabel(Task $task): string
    {
        return match (true) {
            $task->weight !== null && (float) $task->weight > 0 => 'Weight override',
            $task->estimated_hours !== null && (float) $task->estimated_hours > 0 => 'Work estimate',
            default => 'Default',
        };
    }
}
