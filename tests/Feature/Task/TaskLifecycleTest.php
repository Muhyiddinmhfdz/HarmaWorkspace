<?php

namespace Tests\Feature\Task;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Task\Actions\ChangeTaskSchedule;
use App\Domain\Task\Actions\ChangeTaskStatus;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTasks;
use Tests\TestCase;

class TaskLifecycleTest extends TestCase
{
    use CreatesTasks, RefreshDatabase;

    public function test_valid_path_and_side_effects(): void
    {
        $task = $this->createTask();

        $task = $this->moveTask($task, 'TODO');
        $this->assertNull($task->actual_start);

        $task = $this->moveTask($task, 'IN_PROGRESS');
        $this->assertSame(today()->toDateString(), $task->actual_start->toDateString());

        $task = $this->moveTask($task, 'IN_REVIEW', 'DONE');
        $this->assertSame(100, $task->progress_percentage);
        $this->assertNotNull($task->actual_end);
        $this->assertNotNull($task->completed_at);
    }

    public function test_reopen_clears_completion_but_keeps_progress_and_history(): void
    {
        $task = $this->moveTask($this->createTask(), 'TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE', 'REVISION');

        $this->assertNull($task->actual_end);
        $this->assertNull($task->completed_at);
        $this->assertSame(100, $task->progress_percentage);
        $this->assertTrue(Activity::query()->where('event', 'task.completed')->where('subject_id', $task->id)->exists());
        $this->assertTrue(Activity::query()->where('event', 'task.reopened')->where('subject_id', $task->id)->exists());
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $task = $this->createTask();

        try {
            $this->moveTask($task, 'DONE');
            $this->fail('Backlog → Done must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Status Task tidak dapat diubah dari Backlog ke Done.', $e->getMessage());
        }

        $this->expectException(BusinessRuleException::class);
        app(ChangeTaskStatus::class)->handle($task->refresh(), $this->projectStatus('ACTIVE')->id, $this->superAdmin());
    }

    public function test_request_manipulation_cannot_bypass_lifecycle_or_ability(): void
    {
        $pic = $this->staff();
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]), 'TODO', 'IN_PROGRESS', 'IN_REVIEW');

        $this->actingAs($pic);

        // PIC may not approve its own work (Done needs a manager or task.complete).
        Livewire::test('pages::tasks.show', ['task' => $task])->call('moveToStatus', $this->taskStatus('DONE')->id)->assertForbidden();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('moveToStatus', $this->taskStatus('BACKLOG')->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertSame('IN_REVIEW', $task->refresh()->status->code);
    }

    public function test_block_requires_reason_and_resume_clears_blocker(): void
    {
        $task = $this->moveTask($this->createTask(), 'TODO', 'IN_PROGRESS');

        try {
            app(ChangeTaskStatus::class)->handle($task, $this->taskStatus('BLOCKED')->id, $this->superAdmin());
            $this->fail('Blocking without reason must be rejected.');
        } catch (BusinessRuleException) {
        }

        app(ChangeTaskStatus::class)->handle($task->refresh(), $this->taskStatus('BLOCKED')->id, $this->superAdmin(), 'Menunggu akses API Production');
        $task->refresh();
        $this->assertSame('Menunggu akses API Production', $task->blocked_reason);
        $this->assertNotNull($task->blocked_by);
        $this->assertTrue(Activity::query()->where('event', 'task.blocked')->exists());

        $task = $this->moveTask($task, 'IN_PROGRESS');
        $this->assertNull($task->blocked_reason);
        $this->assertTrue(Activity::query()->where('event', 'task.unblocked')->exists());
    }

    public function test_block_via_ui_requires_reason(): void
    {
        $pic = $this->staff();
        $task = $this->moveTask($this->createTask(['department_id' => null, 'pic_user_id' => $pic->id]), 'TODO', 'IN_PROGRESS');

        $this->actingAs($pic);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('moveToStatus', $this->taskStatus('BLOCKED')->id)
            ->assertSet('blockStatusId', $this->taskStatus('BLOCKED')->id)
            ->set('blockReason', '')
            ->call('block')
            ->assertHasErrors(['blockReason' => 'required'])
            ->set('blockReason', 'Vendor belum kirim driver')
            ->call('block')
            ->assertHasNoErrors();

        $this->assertTrue($task->refresh()->status->is_blocked);
    }

    public function test_schedule_baseline_and_history(): void
    {
        $task = $this->createTask(['start_date' => '2026-11-01', 'due_date' => '2026-11-10']);
        $schedule = app(ChangeTaskSchedule::class);

        $this->assertTrue($task->planned_end->equalTo($task->current_end));

        try {
            $schedule->handle($task, '2026-11-01', '2026-11-15', null, $this->superAdmin());
            $this->fail('Reason required once a baseline exists.');
        } catch (BusinessRuleException) {
        }

        $schedule->handle($task->refresh(), '2026-11-01', '2026-11-15', 'Scope bertambah', $this->superAdmin());
        $task->refresh();

        $this->assertSame('2026-11-10', $task->planned_end->toDateString());
        $this->assertSame('2026-11-15', $task->current_end->toDateString());
        $change = $task->scheduleChanges()->sole();
        $this->assertSame('Scope bertambah', $change->reason);
        $this->assertSame('2026-11-10', $change->old_end->toDateString());
    }

    public function test_first_deadline_becomes_baseline_without_reason(): void
    {
        $task = $this->createTask();

        app(ChangeTaskSchedule::class)->handle($task, null, '2026-12-01', null, $this->superAdmin());
        $task->refresh();

        $this->assertSame('2026-12-01', $task->planned_end->toDateString());
        $this->assertSame('2026-12-01', $task->current_end->toDateString());
    }

    public function test_overdue_uses_semantic_flags(): void
    {
        $task = $this->createTask(['due_date' => today()->subDays(2)->toDateString()]);
        $this->assertTrue($task->isOverdue());

        $task = $this->moveTask($task, 'CANCELLED');
        $this->assertFalse($task->isOverdue());
    }
}
