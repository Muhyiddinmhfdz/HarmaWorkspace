<?php

namespace App\Domain\Task\Actions;

use App\Domain\Task\Support\TaskAudit;
use App\Domain\Task\Support\TaskGuard;
use App\Models\ChecklistItem;
use App\Models\Task;
use App\Models\TaskSubtask;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Lightweight checklist of a task or a subtask. Items may be deleted; only
 * completion toggles are logged (on the task timeline) to avoid noise.
 */
class ManageChecklist
{
    public function __construct(
        private TaskGuard $guard,
        private TaskAudit $audit,
    ) {}

    public function add(Task|TaskSubtask $owner, string $text): ChecklistItem
    {
        $this->guard->ensureEditable($this->task($owner));

        $item = new ChecklistItem(['text' => $text]);
        $item->forceFill(['sort_order' => ((int) $owner->checklistItems()->max('sort_order')) + 10]);
        $owner->checklistItems()->save($item);

        return $item;
    }

    public function update(ChecklistItem $item, string $text): ChecklistItem
    {
        $this->guard->ensureEditable($this->taskOf($item));

        $item->fill(['text' => $text])->save();

        return $item;
    }

    public function toggle(ChecklistItem $item, User $actor): ChecklistItem
    {
        $task = $this->taskOf($item);
        $this->guard->ensureEditable($task);

        $completed = ! $item->is_completed;

        $item->forceFill([
            'is_completed' => $completed,
            'completed_at' => $completed ? Date::now() : null,
            'completed_by' => $completed ? $actor->id : null,
        ])->save();

        $this->audit->record($task, 'task.checklist_updated', ($completed ? 'Mencentang checklist "' : 'Menghapus centang checklist "').$item->text.'"');

        return $item;
    }

    public function remove(ChecklistItem $item): void
    {
        $this->guard->ensureEditable($this->taskOf($item));

        $item->delete();
    }

    public function taskOf(ChecklistItem $item): Task
    {
        $owner = $item->checklistable()->firstOrFail();

        return $this->task($owner instanceof Task ? $owner : TaskSubtask::query()->findOrFail($item->checklistable_id));
    }

    private function task(Task|TaskSubtask $owner): Task
    {
        return $owner instanceof Task ? $owner : $owner->task()->firstOrFail();
    }
}
