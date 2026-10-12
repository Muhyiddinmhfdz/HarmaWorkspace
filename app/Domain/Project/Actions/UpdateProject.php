<?php

namespace App\Domain\Project\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\ScopedMasterGuard;
use App\Domain\Project\Support\ProjectGuard;
use App\Enums\MasterScope;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update general project information. Owner, manager, visibility, status,
 * schedule, progress and health have their own actions.
 */
class UpdateProject
{
    public function __construct(
        private AuditLogger $audit,
        private ScopedMasterGuard $masters,
        private ProjectGuard $guard,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, client_id?: int|null, project_category_id: int, priority_id: int}  $data
     */
    public function handle(Project $project, array $data, User $actor): Project
    {
        $this->guard->ensureEditable($project);

        $priorityId = (int) $data['priority_id'];
        $this->masters->priority($priorityId, MasterScope::Project, requireActive: $priorityId !== $project->priority_id);
        $this->guard->activeMaster(ProjectCategory::class, (int) $data['project_category_id'], $project->project_category_id, 'Category');
        $clientId = isset($data['client_id']) ? (int) $data['client_id'] : null;
        $this->guard->activeMaster(Client::class, $clientId, $project->client_id, 'Client');

        return DB::transaction(function () use ($project, $data, $actor, $clientId, $priorityId): Project {
            $project->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'client_id' => $clientId,
                'project_category_id' => (int) $data['project_category_id'],
                'priority_id' => $priorityId,
            ]);

            if (! $project->isDirty()) {
                return $project;
            }

            $project->forceFill(['updated_by' => $actor->id])->save();

            $this->audit->recordChanges('project.updated', 'Memperbarui informasi project '.$project->name, $project);

            return $project;
        });
    }
}
