<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\ApproverType;
use App\Enums\WorkflowEntity;
use App\Models\ApprovalTemplate;
use App\Models\ApprovalTemplateStep;
use App\Models\Department;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Approval templates and their sequential steps. Approver configuration is
 * validated per approver type (never free-form). Running requests keep
 * their own snapshot, so editing a used template only affects new requests.
 */
class ManageApprovalTemplates
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, description?: string|null, entity_type?: string|null}  $data
     */
    public function save(?ApprovalTemplate $template, array $data, User $actor): ApprovalTemplate
    {
        $entity = filled($data['entity_type'] ?? null) ? WorkflowEntity::from((string) $data['entity_type']) : null;
        $creating = $template === null;
        $template ??= new ApprovalTemplate;

        if (! $creating && $template->entity_type !== $entity && $template->transitions()->exists()) {
            throw new BusinessRuleException('Entity tidak dapat diubah karena template sudah dipakai workflow.');
        }

        $template->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $template->forceFill([
            'entity_type' => $entity,
            'updated_by' => $actor->id,
            ...($creating ? ['code' => $this->code($data['name']), 'created_by' => $actor->id, 'is_active' => true] : []),
        ])->save();

        $creating
            ? $this->audit->record('approval_template.created', 'Membuat template approval '.$template->name, $template)
            : $this->audit->recordChanges('approval_template.updated', 'Mengubah template approval '.$template->name, $template);

        return $template;
    }

    public function setActive(ApprovalTemplate $template, bool $active): ApprovalTemplate
    {
        $template->forceFill(['is_active' => $active])->save();
        $this->audit->record($active ? 'approval_template.activated' : 'approval_template.deactivated', ($active ? 'Mengaktifkan' : 'Menonaktifkan').' template approval '.$template->name, $template);

        return $template;
    }

    /**
     * @param  array{name: string, approver_type: string, user_id?: int|null, department_id?: int|null, project_role_id?: int|null, permission?: string|null, minimum_approvals?: int, allow_self_approval?: bool, is_required?: bool}  $data
     */
    public function saveStep(ApprovalTemplate $template, ?ApprovalTemplateStep $step, array $data): ApprovalTemplateStep
    {
        $type = ApproverType::from($data['approver_type']);
        $config = $this->config($type, $data);
        $minimum = max(1, min(10, (int) ($data['minimum_approvals'] ?? 1)));

        if ($minimum > 1 && in_array($type, [ApproverType::SpecificUser, ApproverType::ProjectManager, ApproverType::ProjectOwner, ApproverType::Requester, ApproverType::Creator], true)) {
            throw new BusinessRuleException('Tipe approver ini hanya satu orang; minimum approval harus 1.');
        }

        return DB::transaction(function () use ($template, $step, $data, $type, $config, $minimum): ApprovalTemplateStep {
            $step ??= new ApprovalTemplateStep;

            if ($step->exists && $step->approval_template_id !== $template->id) {
                throw new BusinessRuleException('Tahap tidak berasal dari template ini.');
            }

            $step->forceFill([
                'approval_template_id' => $template->id,
                'step_order' => $step->exists ? $step->step_order : ((int) $template->steps()->max('step_order')) + 1,
                'name' => $data['name'],
                'approver_type' => $type,
                'approver_config' => $config !== [] ? $config : null,
                'minimum_approvals' => $minimum,
                'allow_self_approval' => (bool) ($data['allow_self_approval'] ?? false),
                'is_required' => (bool) ($data['is_required'] ?? true),
            ])->save();

            $this->audit->record('approval_template.step_saved', 'Mengatur tahap "'.$step->name.'" pada template approval '.$template->name, $template);

            return $step;
        });
    }

    public function removeStep(ApprovalTemplate $template, ApprovalTemplateStep $step): void
    {
        if ($step->approval_template_id !== $template->id) {
            throw new BusinessRuleException('Tahap tidak berasal dari template ini.');
        }

        DB::transaction(function () use ($template, $step): void {
            $step->delete();

            // Keep the order contiguous: 1, 2, 3 ...
            $template->steps()->get()->values()->each(fn (ApprovalTemplateStep $remaining, int $index) => $remaining->forceFill(['step_order' => $index + 1])->save());

            $this->audit->record('approval_template.step_removed', 'Menghapus tahap "'.$step->name.'" dari template approval '.$template->name, $template);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function config(ApproverType $type, array $data): array
    {
        return match ($type) {
            ApproverType::SpecificUser => ['user_id' => $this->activeUser($data['user_id'] ?? null)],
            ApproverType::DepartmentLeader => filled($data['department_id'] ?? null) ? ['department_id' => $this->activeDepartment((int) $data['department_id'])] : [],
            ApproverType::ProjectRole => ['project_role_id' => ProjectRole::query()->whereKey((int) ($data['project_role_id'] ?? 0))->value('id') ?? throw new BusinessRuleException('Pilih project role.')],
            ApproverType::Permission => ['permission' => in_array($data['permission'] ?? null, PermissionRegistry::all(), true) ? (string) $data['permission'] : throw new BusinessRuleException('Pilih permission yang valid.')],
            default => [],
        };
    }

    private function activeUser(mixed $id): int
    {
        $user = filled($id) ? User::query()->find((int) $id) : null;

        if ($user === null || ! $user->is_active) {
            throw new BusinessRuleException('Approver harus user aktif.');
        }

        return $user->id;
    }

    private function activeDepartment(int $id): int
    {
        return Department::query()->active()->whereKey($id)->value('id') ?? throw new BusinessRuleException('Department harus aktif.');
    }

    private function code(string $name): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '_')) ?: 'APPROVAL', 34, '');
        $code = $base;
        $suffix = 2;

        while (ApprovalTemplate::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }
}
