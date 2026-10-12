<?php

namespace Tests\Concerns;

use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Task\Actions\ChangeTaskStatus;
use App\Domain\Task\Actions\CreateTask;
use App\Enums\MasterScope;
use App\Models\Department;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;

/**
 * Builds tasks through the real domain actions.
 */
trait CreatesTasks
{
    use CreatesProjects;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTask(array $overrides = [], ?User $actor = null): Task
    {
        $actor ??= $this->superAdmin();

        if (! array_key_exists('department_id', $overrides) && ! array_key_exists('pic_user_id', $overrides)) {
            $overrides['department_id'] = isset($overrides['project_id'])
                ? Project::query()->findOrFail($overrides['project_id'])->leadDepartment()->value('department_id')
                : Department::factory()->create()->id;
        }

        return app(CreateTask::class)->handle([
            'title' => 'API Penjualan',
            'priority_id' => $this->taskPriority('NORMAL')->id,
            ...$overrides,
        ], $actor)->refresh();
    }

    protected function taskStatus(string $code): Status
    {
        return Status::query()->forScope(MasterScope::Task)->where('code', $code)->firstOrFail();
    }

    protected function taskPriority(string $code): Priority
    {
        return Priority::query()->forScope(MasterScope::Task)->where('code', $code)->firstOrFail();
    }

    /**
     * Walk the lifecycle along the given status codes.
     */
    protected function moveTask(Task $task, string ...$codes): Task
    {
        foreach ($codes as $code) {
            app(ChangeTaskStatus::class)->handle($task->refresh(), $this->taskStatus($code)->id, $this->superAdmin(), $code === 'BLOCKED' ? 'Menunggu akses API' : null);
        }

        return $task->refresh();
    }

    protected function addMember(Project $project, User $user): void
    {
        app(ManageProjectMembers::class)->add($project, $user->id, $this->projectRole()->id, null);
    }
}
