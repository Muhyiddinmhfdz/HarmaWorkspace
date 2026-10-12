<?php

namespace Database\Seeders\Development;

use App\Domain\Organization\Actions\CreateUser;
use App\Domain\Project\Actions\ChangeProjectStatus;
use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Actions\ManageProjectMembers;
use App\Domain\Project\Actions\ManageProjectMilestones;
use App\Domain\Project\Actions\UpdateProjectHealth;
use App\Domain\Project\Actions\UpdateProjectProgress;
use App\Enums\MasterScope;
use App\Models\Client;
use App\Models\Department;
use App\Models\Position;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectHealthStatus;
use App\Models\ProjectRole;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\Seeder;

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
