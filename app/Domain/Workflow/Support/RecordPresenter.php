<?php

namespace App\Domain\Workflow\Support;

use App\Enums\WorkflowEntity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Human labels and links for governed records (approval lists, history).
 */
class RecordPresenter
{
    public function type(Model $record): string
    {
        return WorkflowEntity::fromModel($record)->label();
    }

    public function number(Model $record): string
    {
        return (string) $record->getAttribute('number');
    }

    public function title(Model $record): string
    {
        return match (true) {
            $record instanceof Project => $record->name,
            $record instanceof Task, $record instanceof Ticket => $record->title,
            default => '',
        };
    }

    public function url(Model $record): string
    {
        return match (true) {
            $record instanceof Project => route('projects.show', $record),
            $record instanceof Task => route('tasks.show', $record),
            $record instanceof Ticket => route('tickets.show', $record),
            default => '#',
        };
    }
}
