<?php

namespace App\Enums;

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Domains a workflow can govern. Technical constant: the value is the morph
 * alias of the governed model, so no class name ever comes from user input.
 */
enum WorkflowEntity: string
{
    case Project = 'project';
    case Task = 'task';
    case Ticket = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Task => 'Task',
            self::Ticket => 'Ticket',
        };
    }

    /**
     * @return class-string<Project|Task|Ticket>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Project => Project::class,
            self::Task => Task::class,
            self::Ticket => Ticket::class,
        };
    }

    public function statusScope(): MasterScope
    {
        return MasterScope::from($this->value);
    }

    public static function fromModel(Model $model): self
    {
        return match (true) {
            $model instanceof Project => self::Project,
            $model instanceof Task => self::Task,
            $model instanceof Ticket => self::Ticket,
            default => throw new \InvalidArgumentException('Model ['.$model::class.'] is not governed by a workflow.'),
        };
    }
}
