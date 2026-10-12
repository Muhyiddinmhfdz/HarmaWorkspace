<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

/**
 * General workflow settings: name/description, active flag, default flag.
 * A workflow is usable when active AND published; the default must stay
 * usable, so it cannot be deactivated (choose another default first).
 */
class UpdateWorkflow
{
    public function __construct(
        private AuditLogger $audit,
        private WorkflowRegistry $registry,
    ) {}

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function details(Workflow $workflow, array $data, User $actor): Workflow
    {
        $workflow->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $workflow->forceFill(['updated_by' => $actor->id])->save();
        $this->audit->recordChanges('workflow.updated', 'Mengubah workflow '.$workflow->name, $workflow);

        return $workflow;
    }

    public function setActive(Workflow $workflow, bool $active, User $actor): Workflow
    {
        if (! $active && $workflow->is_default) {
            throw new BusinessRuleException('Workflow default tidak dapat dinonaktifkan. Jadikan workflow lain sebagai default terlebih dahulu.');
        }

        $workflow->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
        $this->audit->record($active ? 'workflow.activated' : 'workflow.deactivated', ($active ? 'Mengaktifkan' : 'Menonaktifkan').' workflow '.$workflow->name, $workflow);

        return $workflow;
    }

    /**
     * Make this workflow the default for new records of its entity.
     */
    public function makeDefault(Workflow $workflow, User $actor): Workflow
    {
        if (! $workflow->is_active || ! $workflow->publishedVersion()->exists()) {
            throw new BusinessRuleException('Hanya workflow aktif yang sudah memiliki versi published yang dapat dijadikan default.');
        }

        return DB::transaction(function () use ($workflow, $actor): Workflow {
            $previous = Workflow::query()->forEntity($workflow->entity_type)->where('is_default', true)->whereKeyNot($workflow->id)->lockForUpdate()->first();
            $previous?->forceFill(['is_default' => false])->save();
            $workflow->forceFill(['is_default' => true, 'updated_by' => $actor->id])->save();
            $this->registry->forget();

            $this->audit->record('workflow.default_changed', 'Menjadikan '.$workflow->name.' default untuk '.$workflow->entity_type->label().' baru', $workflow, old: ['default' => $previous?->name], new: ['default' => $workflow->name]);

            return $workflow;
        });
    }
}
