<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Support\ProjectGuard;
use App\Domain\Project\Support\VisibilityGuard;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\System\Numbering\NumberGenerator;
use App\Domain\System\Settings\SettingKey;
use App\Domain\Workflow\Support\WorkflowGate;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Enums\MasterScope;
use App\Enums\ProgressMode;
use App\Enums\ProjectVisibility;
use App\Enums\WorkflowEntity;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDepartment;
use App\Models\ProjectHealthStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Create a project with its number, lead and supporting departments and the
 * initial schedule baseline (planned = current, actual empty).
 */
class CreateProject
{
    public function __construct(
        private AuditLogger $audit,
        private NumberGenerator $numbers,
        private ScopedMasterGuard $masters,
        private ProjectGuard $guard,
        private VisibilityGuard $visibilityGuard,
        private WorkflowRegistry $workflows,
        private WorkflowGate $workflow,
    ) {}

    /**
     * @param  array{name: string, workflow_id?: int|null, description?: string|null, client_id?: int|null, project_category_id: int, priority_id: int, visibility?: string, owner_id?: int|null, project_manager_id: int, lead_department_id: int, lead_user_id?: int|null, supporting_department_ids?: list<int>, start_date: string, end_date: string}  $data
     */
    public function handle(array $data, User $actor): Project
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($end->lt($start)) {
            throw new BusinessRuleException('Target deadline tidak boleh sebelum tanggal mulai.');
        }

        $priority = $this->masters->priority((int) $data['priority_id'], MasterScope::Project);
        $version = $this->workflows->versionForNew(WorkflowEntity::Project, filled($data['workflow_id'] ?? null) ? (int) $data['workflow_id'] : null);
        $status = $this->workflows->initialState($version)->status;
        $health = ProjectHealthStatus::query()->active()->where('is_default', true)->first()
            ?? throw new BusinessRuleException('Belum ada Project Health default. Atur di Master Data.');

        $this->guard->activeMaster(ProjectCategory::class, (int) $data['project_category_id'], null, 'Category');
        $this->guard->activeMaster(Client::class, isset($data['client_id']) ? (int) $data['client_id'] : null, null, 'Client');
        $manager = $this->guard->activeUser((int) $data['project_manager_id'], 'Project Manager');
        $owner = filled($data['owner_id'] ?? null) ? $this->guard->activeUser((int) $data['owner_id'], 'Project Owner') : null;

        $visibility = ProjectVisibility::tryFrom($data['visibility'] ?? ProjectVisibility::Restricted->value)
            ?? throw new BusinessRuleException('Visibility project tidak valid.');
        $this->visibilityGuard->ensureCanSet($visibility, $actor);

        $leadDepartmentId = (int) $data['lead_department_id'];
        $supportingIds = array_values(array_unique(array_map(intval(...), $data['supporting_department_ids'] ?? [])));

        if (in_array($leadDepartmentId, $supportingIds, true)) {
            throw new BusinessRuleException('Lead department tidak boleh dipilih lagi sebagai supporting department.');
        }

        return DB::transaction(function () use ($data, $actor, $start, $end, $priority, $status, $health, $manager, $owner, $leadDepartmentId, $supportingIds, $visibility, $version): Project {
            $project = new Project([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'project_category_id' => (int) $data['project_category_id'],
                'priority_id' => $priority->id,
            ]);

            $project->forceFill([
                'number' => $this->numbers->next('project', SettingKey::ProjectNumberPrefix),
                'visibility' => $visibility,
                'status_id' => $status->id,
                'workflow_version_id' => $version->id,
                'health_status_id' => $health->id,
                'owner_id' => $owner?->id,
                'project_manager_id' => $manager->id,
                'planned_start' => $start,
                'planned_end' => $end,
                'current_start' => $start,
                'current_end' => $end,
                'progress_mode' => ProgressMode::Manual,
                'progress_percentage' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $lead = $this->guard->activeDepartment($leadDepartmentId);
            $leadRecord = new ProjectDepartment([
                'project_id' => $project->id,
                'department_id' => $lead->id,
                'lead_user_id' => filled($data['lead_user_id'] ?? null) ? $this->guard->activeUser((int) $data['lead_user_id'], 'Department lead')->id : null,
            ]);
            $leadRecord->forceFill(['is_lead' => true])->save();

            $supportingNames = [];

            foreach ($supportingIds as $departmentId) {
                $department = $this->guard->activeDepartment($departmentId);
                ProjectDepartment::query()->create(['project_id' => $project->id, 'department_id' => $department->id]);
                $supportingNames[] = $department->name;
            }

            $this->audit->recordCreated('project.created', 'Membuat project '.$project->name, $project, [
                'project_manager' => $manager->name,
                'owner' => $owner?->name,
                'lead_department' => $lead->name,
                'supporting_departments' => $supportingNames,
            ]);
            $this->workflow->record($project, null, null, $status, $actor, null, ['event' => 'created']);

            return $project;
        });
    }
}
