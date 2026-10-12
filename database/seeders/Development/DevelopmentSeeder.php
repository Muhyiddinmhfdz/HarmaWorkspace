<?php

namespace Database\Seeders\Development;

use App\Domain\Approval\Actions\ManageApprovalTemplates;
use App\Domain\Organization\Actions\CreateUser;
use App\Domain\Project\Actions\ChangeProjectProgressMode;
use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Project\Actions\ManageProjectMilestones;
use App\Domain\Project\Actions\UpdateProjectHealth;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Domain\Task\Actions\ChangeTaskStatus;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Actions\UpdateTaskProgress;
use App\Domain\Ticket\Actions\AcceptTicket;
use App\Domain\Ticket\Actions\ChangeTicketStatus;
use App\Domain\Ticket\Actions\CreateTicket;
use App\Domain\Ticket\Actions\ManageTicketAssignments;
use App\Domain\Workflow\Actions\CreateWorkflow;
use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Domain\Workflow\Actions\ManageWorkflowDraft;
use App\Domain\Workflow\Actions\PublishWorkflowVersion;
use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Enums\WorkflowRuleType;
use App\Models\ApprovalTemplate;
use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectRole;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketResolution;
use App\Models\TicketSource;
use App\Models\TicketSubcategory;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTransition;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo data for local development only. Names here are sample data and must
 * never be referenced by application logic. Demo accounts use the password
 * "password".
 */
class DevelopmentSeeder extends Seeder
{
    public function run(CreateUser $createUser): void
    {
        $positions = collect([
            ['code' => 'STAFF', 'name' => 'Staff', 'level' => 10],
            ['code' => 'SPV', 'name' => 'Supervisor', 'level' => 20],
            ['code' => 'COORD', 'name' => 'Coordinator', 'level' => 30],
            ['code' => 'MGR', 'name' => 'Manager', 'level' => 40],
            ['code' => 'HEAD', 'name' => 'Head', 'level' => 50],
        ])->mapWithKeys(fn (array $row, int $index): array => [
            $row['code'] => Position::query()->firstOrCreate(['code' => $row['code']], [...$row, 'sort_order' => ($index + 1) * 10]),
        ]);

        $technology = Department::query()->firstOrCreate(
            ['code' => 'TECH'],
            ['name' => 'Technology', 'description' => 'Induk divisi teknologi.', 'sort_order' => 10],
        );

        $departments = collect([
            ['code' => 'TS', 'name' => 'Technical Support', 'parent_id' => $technology->id],
            ['code' => 'DEV', 'name' => 'Programmer', 'parent_id' => $technology->id],
            ['code' => 'QA', 'name' => 'QA', 'parent_id' => $technology->id],
            ['code' => 'UIUX', 'name' => 'UI/UX', 'parent_id' => $technology->id],
            ['code' => 'FIN', 'name' => 'Finance', 'parent_id' => null],
            ['code' => 'HR', 'name' => 'HR', 'parent_id' => null],
            ['code' => 'OPS', 'name' => 'Operational', 'parent_id' => null],
            ['code' => 'MGT', 'name' => 'Management', 'parent_id' => null],
        ])->mapWithKeys(fn (array $row, int $index): array => [
            $row['code'] => Department::query()->firstOrCreate(['code' => $row['code']], [...$row, 'sort_order' => ($index + 2) * 10]),
        ]);

        $demoUsers = [
            ['Rina Manajemen', 'rina', 'Management', [['MGT', 'HEAD', true, true]]],
            ['Andi Programmer', 'andi', 'Staff', [['DEV', 'COORD', true, true], ['QA', 'STAFF', false, false]]],
            ['Budi Programmer', 'budi', 'Staff', [['DEV', 'STAFF', true, false]]],
            ['Sari Support', 'sari', 'Staff', [['TS', 'SPV', true, true]]],
            ['Dewi Designer', 'dewi', 'Staff', [['UIUX', 'STAFF', true, false]]],
        ];

        foreach ($demoUsers as [$name, $username, $role, $memberships]) {
            if (User::query()->where('username', $username)->exists()) {
                continue;
            }

            $createUser->handle([
                'name' => $name,
                'username' => $username,
                'email' => $username.'@harmaworkspace.test',
                'password' => 'password',
                'role' => $role,
                'memberships' => array_map(fn (array $membership): array => [
                    'department_id' => $departments[$membership[0]]->id,
                    'position_id' => $positions[$membership[1]]->id,
                    'is_primary' => $membership[2],
                    'is_leader' => $membership[3],
                ], $memberships),
            ], actor: null);
        }

        if (Client::query()->doesntExist()) {
            Client::factory()->count(12)->create();
        }

        $this->seedProjects($departments->all());
        $this->seedTasks();
        $this->seedTickets();
        $this->seedWorkflowDemo();
    }

    /**
     * Demo ticket masters and tickets in every queue state, created through
     * the domain actions as the demo users (so the activity shows who did what).
     */
    private function seedTickets(): void
    {
        foreach (['Bug', 'Request', 'Incident', 'Question', 'Improvement', 'Change Request', 'Access Request', 'Data Request'] as $index => $name) {
            TicketType::query()->firstOrCreate(['code' => strtoupper(str_replace(' ', '_', $name))], ['name' => $name, 'color' => ['red', 'sky', 'orange', 'violet', 'teal', 'indigo', 'amber', 'cyan'][$index], 'sort_order' => ($index + 1) * 10]);
        }

        $categories = [
            'Application' => ['Login', 'Transaction', 'Report', 'Integration'],
            'Account' => ['Password Reset', 'Access'],
            'Data' => ['Correction', 'Export'],
            'Infrastructure' => ['Network', 'VPN', 'Hardware'],
            'Operational' => [],
            'Support' => [],
        ];

        foreach (array_keys($categories) as $index => $name) {
            $category = TicketCategory::query()->firstOrCreate(['code' => strtoupper($name)], ['name' => $name, 'sort_order' => ($index + 1) * 10]);

            foreach ($categories[$name] as $subIndex => $subName) {
                TicketSubcategory::query()->firstOrCreate(
                    ['code' => strtoupper(substr($name, 0, 3)).'_'.strtoupper(str_replace(' ', '_', $subName))],
                    ['name' => $subName, 'ticket_category_id' => $category->id, 'sort_order' => ($subIndex + 1) * 10],
                );
            }
        }

        foreach (['Internal', 'WhatsApp', 'Phone', 'Email', 'Meeting', 'Monitoring'] as $index => $name) {
            TicketSource::query()->firstOrCreate(['code' => strtoupper($name)], ['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }

        if (Ticket::query()->exists()) {
            return;
        }

        $users = User::query()->whereIn('username', ['rina', 'andi', 'budi', 'sari', 'dewi'])->get()->keyBy('username');

        if ($users->count() < 5) {
            return;
        }

        $department = fn (string $code): int => (int) Department::query()->where('code', $code)->value('id');
        $type = fn (string $code): int => (int) TicketType::query()->where('code', $code)->value('id');
        $category = fn (string $code): int => (int) TicketCategory::query()->where('code', $code)->value('id');
        $subcategory = fn (string $code): ?int => TicketSubcategory::query()->where('code', $code)->value('id');
        $source = fn (string $code): ?int => TicketSource::query()->where('code', $code)->value('id');
        $priority = fn (string $code): int => (int) Priority::query()->forScope(MasterScope::Ticket)->where('code', $code)->value('id');
        $pos = Project::query()->where('name', 'POS Development')->first();
        $apiTask = Task::query()->where('title', 'API Penjualan')->first();
        $status = app(ChangeTicketStatus::class);
        $assign = app(ManageTicketAssignments::class);

        // New (not accepted yet), critical, related to the POS project task.
        $this->createTicketAs($users['sari'], [
            'title' => 'Error transaksi POS saat pembayaran',
            'description' => "Client melaporkan error 500 saat pembayaran non-tunai.\nTerjadi sejak pagi di 3 outlet.",
            'ticket_type_id' => $type('BUG'), 'ticket_category_id' => $category('APPLICATION'), 'ticket_subcategory_id' => $subcategory('APP_TRANSACTION'),
            'ticket_source_id' => $source('WHATSAPP'), 'priority_id' => $priority('CRITICAL'), 'current_department_id' => $department('DEV'),
            'project_id' => $pos?->id, 'task_id' => $pos !== null && $apiTask?->project_id === $pos->id ? $apiTask->id : null,
            'client_id' => $pos?->client_id, 'due_date' => now()->addDay()->toDateString(),
        ]);

        // Accepted, still without PIC.
        $unassigned = $this->createTicketAs($users['sari'], [
            'title' => 'Tambah filter tanggal di laporan penjualan',
            'ticket_type_id' => $type('REQUEST'), 'ticket_category_id' => $category('APPLICATION'), 'ticket_subcategory_id' => $subcategory('APP_REPORT'),
            'ticket_source_id' => $source('EMAIL'), 'current_department_id' => $department('DEV'), 'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->as($users['andi'], fn () => app(AcceptTicket::class)->handle($unassigned, $users['andi']));

        // Assigned, not started.
        $assigned = $this->createTicketAs($users['dewi'], [
            'title' => 'Reset akses akun kasir cabang 2',
            'ticket_type_id' => $type('ACCESS_REQUEST'), 'ticket_category_id' => $category('ACCOUNT'), 'ticket_subcategory_id' => $subcategory('ACC_ACCESS'),
            'ticket_source_id' => $source('INTERNAL'), 'current_department_id' => $department('DEV'),
        ]);
        $this->as($users['andi'], fn () => $assign->assign($assigned, $users['budi']->id, $users['andi']));

        // In progress.
        $working = $this->createTicketAs($users['sari'], [
            'title' => 'Sinkronisasi stok tidak terupdate',
            'ticket_type_id' => $type('BUG'), 'ticket_category_id' => $category('APPLICATION'), 'ticket_subcategory_id' => $subcategory('APP_INTEGRATION'),
            'ticket_source_id' => $source('PHONE'), 'priority_id' => $priority('HIGH'), 'current_department_id' => $department('DEV'),
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->as($users['andi'], fn () => $assign->assign($working, $users['budi']->id, $users['andi']));
        $this->as($users['budi'], fn () => $status->start($working->refresh(), $users['budi']));

        // Waiting for information from the requester.
        $needInfo = $this->createTicketAs($users['sari'], [
            'title' => 'Laporan harian kosong untuk outlet Bandung',
            'ticket_type_id' => $type('BUG'), 'ticket_category_id' => $category('APPLICATION'), 'ticket_subcategory_id' => $subcategory('APP_REPORT'),
            'ticket_source_id' => $source('WHATSAPP'), 'current_department_id' => $department('DEV'),
        ]);
        $this->as($users['andi'], fn () => $assign->assign($needInfo, $users['budi']->id, $users['andi']));
        $this->as($users['budi'], function () use ($status, $needInfo, $users): void {
            $status->start($needInfo->refresh(), $users['budi']);
            $status->requestInformation($needInfo->refresh(), 'Mohon kirimkan tanggal laporan dan user yang mengalami kendala.', $users['budi']);
        });

        // In review.
        $review = $this->createTicketAs($users['sari'], [
            'title' => 'Perbaikan format struk pembayaran',
            'ticket_type_id' => $type('IMPROVEMENT'), 'ticket_category_id' => $category('APPLICATION'), 'current_department_id' => $department('DEV'),
        ]);
        $this->as($users['andi'], fn () => $assign->assign($review, $users['budi']->id, $users['andi']));
        $this->as($users['budi'], function () use ($status, $review, $users): void {
            $status->start($review->refresh(), $users['budi']);
            $status->sendToReview($review->refresh(), $users['budi'], 'Format struk sudah disesuaikan, mohon dicek di staging.');
        });

        // Resolved, waiting for the requester to close.
        $resolved = $this->createTicketAs($users['budi'], [
            'title' => 'Printer struk tidak terdeteksi di kasir 3',
            'ticket_type_id' => $type('INCIDENT'), 'ticket_category_id' => $category('INFRASTRUCTURE'), 'ticket_subcategory_id' => $subcategory('INF_HARDWARE'),
            'ticket_source_id' => $source('PHONE'), 'current_department_id' => $department('TS'),
        ]);
        $this->as($users['sari'], function () use ($assign, $status, $resolved, $users): void {
            $assign->assign($resolved, $users['sari']->id, $users['sari']);
            $status->start($resolved->refresh(), $users['sari']);
            $status->sendToReview($resolved->refresh(), $users['sari']);
            $status->resolve($resolved->refresh(), TicketResolution::query()->where('code', 'FIXED')->value('id'), 'Driver printer diperbarui dan kabel USB diganti.', $users['sari']);
        });

        // Overdue.
        $overdue = $this->createTicketAs($users['rina'], [
            'title' => 'Instalasi VPN untuk tim Finance',
            'ticket_type_id' => $type('REQUEST'), 'ticket_category_id' => $category('INFRASTRUCTURE'), 'ticket_subcategory_id' => $subcategory('INF_VPN'),
            'ticket_source_id' => $source('MEETING'), 'current_department_id' => $department('TS'), 'due_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->as($users['sari'], function () use ($assign, $status, $overdue, $users): void {
            $assign->assign($overdue, $users['sari']->id, $users['sari']);
            $status->start($overdue->refresh(), $users['sari']);
        });
    }

    /**
     * Demo approval templates and two published, non-default workflows that
     * use them (default workflows stay untouched), plus one ticket waiting
     * for its first approval step.
     */
    private function seedWorkflowDemo(): void
    {
        if (ApprovalTemplate::query()->exists()) {
            return;
        }

        $admin = User::role(config('harma.super_admin_role'))->first();
        $users = User::query()->whereIn('username', ['andi', 'budi', 'sari', 'dewi'])->get()->keyBy('username');

        if ($admin === null || $users->count() < 4) {
            return;
        }

        $templates = app(ManageApprovalTemplates::class);
        $department = fn (string $code): int => (int) Department::query()->where('code', $code)->value('id');

        $ticketApproval = $templates->save(null, ['name' => 'Ticket QA + TS Approval', 'entity_type' => 'ticket', 'description' => 'Review oleh lead programmer lalu diterima Technical Support.'], $admin);
        $templates->saveStep($ticketApproval, null, ['name' => 'Programmer Lead Review', 'approver_type' => 'department_leader', 'department_id' => $department('DEV')]);
        $templates->saveStep($ticketApproval, null, ['name' => 'Technical Support Acceptance', 'approver_type' => 'department_leader', 'department_id' => $department('TS')]);

        $taskApproval = $templates->save(null, ['name' => 'Task Simple Review', 'entity_type' => 'task', 'description' => 'Leader department task menyetujui penyelesaian.'], $admin);
        $templates->saveStep($taskApproval, null, ['name' => 'Department Lead Review', 'approver_type' => 'department_leader']);

        $bugWorkflow = $this->workflowWithApproval('ticket', 'Bug Ticket Workflow (QA + TS)', 'IN_REVIEW', 'RESOLVED', $ticketApproval->id, $admin);
        $this->workflowWithApproval('task', 'Task with Review Approval', 'IN_REVIEW', 'DONE', $taskApproval->id, $admin);

        // A bug ticket waiting for the first approval step (Andi leads DEV).
        $ticket = $this->createTicketAs($users['dewi'], [
            'title' => 'Tombol simpan tidak merespons di form retur',
            'ticket_type_id' => (int) TicketType::query()->where('code', 'BUG')->value('id'),
            'ticket_category_id' => (int) TicketCategory::query()->where('code', 'APPLICATION')->value('id'),
            'current_department_id' => $department('DEV'),
            'workflow_id' => $bugWorkflow->id,
        ]);
        $this->as($users['andi'], fn () => app(ManageTicketAssignments::class)->assign($ticket, $users['budi']->id, $users['andi']));
        $this->as($users['budi'], function () use ($ticket, $users): void {
            app(ChangeTicketStatus::class)->start($ticket->refresh(), $users['budi']);
            app(ChangeTicketStatus::class)->sendToReview($ticket->refresh(), $users['budi'], 'Sudah diperbaiki, mohon dicek.');
        });

        $resolve = WorkflowTransition::query()->where('workflow_version_id', $ticket->refresh()->workflow_version_id)->where('operation', 'resolve')->firstOrFail();
        $resolution = (int) TicketResolution::query()->where('code', 'FIXED')->value('id');
        $this->as($users['dewi'], fn () => app(ExecuteWorkflowTransition::class)->handle($ticket->refresh(), $resolve->id, $users['dewi'], ['resolution_id' => $resolution, 'notes' => 'Tombol sudah berfungsi di staging.']));
    }

    /**
     * Copy of the default workflow where one transition requires approval.
     */
    private function workflowWithApproval(string $entity, string $name, string $fromCode, string $toCode, int $templateId, User $admin): Workflow
    {
        $default = Workflow::query()->where('entity_type', $entity)->where('is_default', true)->firstOrFail();
        $workflow = app(CreateWorkflow::class)->handle(['name' => $name, 'entity_type' => $entity, 'copy_from_workflow_id' => $default->id, 'description' => 'Contoh workflow dengan approval (data demo).'], $admin);
        $draft = $workflow->draftVersion()->firstOrFail();
        $transition = WorkflowTransition::query()->where('workflow_version_id', $draft->id)
            ->whereHas('fromState', fn ($query) => $query->where('code', $fromCode))
            ->whereHas('toState', fn ($query) => $query->where('code', $toCode))
            ->with('rules')
            ->firstOrFail();

        app(ManageWorkflowDraft::class)->saveTransition($draft, $transition, [
            'from_state_id' => $transition->from_state_id,
            'to_state_id' => $transition->to_state_id,
            'action_label' => $transition->action_label,
            'operation' => $transition->operation,
            'roles' => $transition->rule(WorkflowRuleType::ContextRole)?->values('roles') ?? [],
            'permissions' => $transition->rule(WorkflowRuleType::Permission)?->values('permissions') ?? [],
            'requirements' => array_values($transition->rules->reject(fn ($rule) => $rule->type->isWhoRule())->map(fn ($rule): string => $rule->type->value)->all()),
            'approval_template_id' => $templateId,
        ], $admin);

        app(PublishWorkflowVersion::class)->handle($draft, $admin);

        return $workflow;
    }

    /**
     * @param  array{title: string, ticket_type_id: int, ticket_category_id: int, current_department_id: int, workflow_id?: int|null, description?: string, priority_id?: int, ticket_subcategory_id?: int|null, ticket_source_id?: int|null, client_id?: int|null, project_id?: int|null, task_id?: int|null, due_date?: string|null}  $data
     */
    private function createTicketAs(User $requester, array $data): Ticket
    {
        $data = [
            'description' => 'Detail kendala sudah dikonfirmasi ke user. Mohon ditindaklanjuti.',
            'priority_id' => (int) Priority::query()->forScope(MasterScope::Ticket)->where('code', 'NORMAL')->value('id'),
            ...$data,
        ];

        return $this->as($requester, fn (): Ticket => app(CreateTicket::class)->handle($data, $requester));
    }

    /**
     * Run a seeding step as the given user so the activity log records them.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function as(User $user, Closure $callback): mixed
    {
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            return $callback();
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /**
     * Demo task masters and tasks, created through the domain actions.
     */
    private function seedTasks(): void
    {
        foreach (['Feature', 'Bug Fix', 'Improvement', 'Maintenance', 'Research', 'Documentation', 'Testing', 'Operational'] as $index => $name) {
            TaskType::query()->firstOrCreate(['code' => strtoupper(str_replace(' ', '_', $name))], ['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }

        foreach (['Development', 'UI/UX', 'Quality Assurance', 'Support', 'Infrastructure', 'Administration'] as $index => $name) {
            TaskCategory::query()->firstOrCreate(['code' => strtoupper(preg_replace('/[^A-Za-z]+/', '_', $name))], ['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }

        if (Task::query()->exists()) {
            return;
        }

        $actor = User::role(config('harma.super_admin_role'))->first() ?? User::query()->firstOrFail();
        $status = fn (string $code): int => (int) Status::query()->forScope(MasterScope::Task)->where('code', $code)->value('id');
        $priority = fn (string $code): int => (int) Priority::query()->forScope(MasterScope::Task)->where('code', $code)->value('id');
        $type = fn (string $code): ?int => TaskType::query()->where('code', $code)->value('id');
        // Target status => [steps before the progress update, steps after it, blocked reason].
        $path = [
            'TODO' => [['TODO'], [], null],
            'IN_PROGRESS' => [['TODO', 'IN_PROGRESS'], [], null],
            'BLOCKED' => [['TODO', 'IN_PROGRESS'], ['BLOCKED'], 'Menunggu driver printer dari vendor.'],
            'IN_REVIEW' => [['TODO', 'IN_PROGRESS'], ['IN_REVIEW'], null],
            'DONE' => [['TODO', 'IN_PROGRESS'], ['IN_REVIEW', 'DONE'], null],
        ];

        $pos = Project::query()->where('name', 'POS Development')->first();

        if ($pos !== null) {
            $departmentOf = fn (string $code): int => (int) Department::query()->where('code', $code)->value('id');
            $pm = $pos->project_manager_id;
            $dewi = (int) User::query()->where('username', 'dewi')->value('id');
            $sari = (int) User::query()->where('username', 'sari')->value('id');

            $tasks = [
                ['Desain UI kasir', 'UIUX', $dewi, 'DONE', 100, 12, 'FEATURE', -8],
                ['API Penjualan', 'DEV', $pm, 'IN_PROGRESS', 60, 24, 'FEATURE', 5],
                ['Integrasi printer struk', 'DEV', $pm, 'BLOCKED', 30, 8, 'FEATURE', 3],
                ['Test skenario pembayaran', 'QA', $sari, 'TODO', 0, 10, 'TESTING', 12],
                ['Setup server staging', 'TS', $sari, 'IN_REVIEW', 90, 6, 'MAINTENANCE', -1],
            ];

            foreach ($tasks as [$title, $dept, $pic, $target, $progress, $hours, $typeCode, $dueOffset]) {
                $task = app(CreateTask::class)->handle([
                    'title' => $title,
                    'project_id' => $pos->id,
                    'department_id' => $departmentOf($dept),
                    'pic_user_id' => $pic,
                    'priority_id' => $priority($target === 'BLOCKED' ? 'HIGH' : 'NORMAL'),
                    'task_type_id' => $type($typeCode),
                    'due_date' => now()->addDays($dueOffset)->toDateString(),
                    'estimated_hours' => $hours,
                ], $actor);

                [$before, $after, $reason] = $path[$target];

                foreach ($before as $step) {
                    app(ChangeTaskStatus::class)->handle($task->refresh(), $status($step), $actor);
                }

                if ($progress > 0 && $target !== 'DONE') {
                    app(UpdateTaskProgress::class)->handle($task->refresh(), $progress, $actor);
                }

                foreach ($after as $step) {
                    app(ChangeTaskStatus::class)->handle($task->refresh(), $status($step), $actor, $reason);
                }
            }

            app(ChangeProjectProgressMode::class)->handle($pos->refresh(), ProgressMode::TaskWeighted, $actor);
        }

        // A standalone operational task.
        app(CreateTask::class)->handle([
            'title' => 'Verifikasi backup harian server',
            'department_id' => (int) Department::query()->where('code', 'TS')->value('id'),
            'pic_user_id' => (int) User::query()->where('username', 'sari')->value('id'),
            'priority_id' => $priority('NORMAL'),
            'task_type_id' => $type('OPERATIONAL'),
            'due_date' => now()->toDateString(),
            'estimated_hours' => 0.5,
        ], $actor);
    }

    /**
     * Demo projects created through the real domain actions.
     *
     * @param  array<string, Department>  $departments
     */
    private function seedProjects(array $departments): void
    {
        foreach (['Development', 'Implementation', 'Maintenance', 'Migration', 'Internal System', 'Infrastructure', 'Research'] as $index => $name) {
            ProjectCategory::query()->firstOrCreate(
                ['code' => strtoupper(str_replace(' ', '_', $name))],
                ['name' => $name, 'sort_order' => ($index + 1) * 10],
            );
        }

        if (Project::query()->exists()) {
            return;
        }

        $actor = User::role(config('harma.super_admin_role'))->first() ?? User::query()->firstOrFail();
        /** @var array<string, int> $users */
        $users = User::query()->pluck('id', 'username')->map(fn (mixed $id): int => (int) $id)->all();
        $clientIds = array_map(intval(...), Client::query()->orderBy('id')->limit(2)->pluck('id')->all());
        $priority = fn (string $code): int => (int) Priority::query()->forScope(MasterScope::Project)->where('code', $code)->value('id');
        $status = fn (string $code): int => (int) Status::query()->forScope(MasterScope::Project)->where('code', $code)->value('id');
        $role = fn (string $code): int => (int) ProjectRole::query()->where('code', $code)->value('id');

        $demo = [
            ['POS Development', 'DEVELOPMENT', 'HIGH', 'andi', 'rina', 'DEV', ['QA', 'UIUX', 'TS'], -20, 45, 'ACTIVE', 45, 'ON_TRACK', $clientIds[0] ?? null],
            ['HRIS Internal', 'INTERNAL_SYSTEM', 'NORMAL', 'budi', null, 'DEV', ['HR'], -40, 10, 'ACTIVE', 70, 'AT_RISK', null],
            ['Network Upgrade', 'INFRASTRUCTURE', 'CRITICAL', 'sari', 'rina', 'TS', [], -5, 25, 'PLANNING', 10, 'ON_TRACK', $clientIds[1] ?? null],
        ];

        foreach ($demo as [$name, $category, $priorityCode, $pm, $owner, $lead, $supporting, $startOffset, $duration, $statusCode, $progress, $healthCode, $clientId]) {
            $project = app(CreateProject::class)->handle([
                'name' => $name,
                'description' => 'Project demo untuk pengembangan lokal.',
                'client_id' => $clientId,
                'project_category_id' => (int) ProjectCategory::query()->where('code', $category)->value('id'),
                'priority_id' => $priority($priorityCode),
                'owner_id' => $owner ? $users[$owner] : null,
                'project_manager_id' => $users[$pm],
                'lead_department_id' => $departments[$lead]->id,
                'supporting_department_ids' => array_values(array_map(fn (string $code): int => $departments[$code]->id, $supporting)),
                'start_date' => now()->addDays($startOffset)->toDateString(),
                'end_date' => now()->addDays($startOffset + $duration)->toDateString(),
            ], $actor);

            // Walk the lifecycle (Draft → Planning → Active) instead of jumping.
            foreach (['PLANNING', 'ACTIVE'] as $step) {
                app(ChangeProjectStatus::class)->handle($project->refresh(), $status($step), $actor);

                if ($step === $statusCode) {
                    break;
                }
            }
            app(UpdateProjectProgress::class)->handle($project, $progress, $actor);
            app(UpdateProjectHealth::class)->handle($project, (int) ProjectHealthStatus::query()->where('code', $healthCode)->value('id'), null, $actor);

            foreach (['dewi' => 'DEVELOPER', 'sari' => 'REVIEWER'] as $username => $roleCode) {
                if ($users[$username] !== $project->project_manager_id) {
                    app(ManageProjectMembers::class)->add($project, $users[$username], $role($roleCode), null);
                }
            }

            foreach (['Requirement Approved' => -10, 'Design Completed' => 5, 'Development Completed' => 20, 'Go Live' => $startOffset + $duration] as $milestone => $offset) {
                $created = app(ManageProjectMilestones::class)->create($project, ['name' => $milestone, 'due_date' => now()->addDays($offset)->toDateString(), 'is_key_milestone' => $milestone === 'Go Live'], $actor);

                if ($offset < 0) {
                    app(ManageProjectMilestones::class)->complete($created, now()->addDays($offset)->toDateString(), $actor);
                }
            }
        }
    }
}
