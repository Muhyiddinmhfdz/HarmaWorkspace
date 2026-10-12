<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Domain\Workflow\Support\WorkflowVersionValidator;
use App\Enums\WorkflowVersionStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Validate and publish a draft (ADR-067). The previous published version
 * becomes archived: records already running on it keep using it; new
 * records use the new version. Locked so two publishers cannot race.
 */
class PublishWorkflowVersion
{
    public function __construct(
        private WorkflowVersionValidator $validator,
        private WorkflowRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function handle(WorkflowVersion $version, User $actor): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $actor): WorkflowVersion {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($version->workflow_id);
            $version = WorkflowVersion::query()->lockForUpdate()->findOrFail($version->id);

            if (! $version->isDraft()) {
                throw new BusinessRuleException('Hanya draft yang dapat dipublikasikan.');
            }

            $report = $this->validator->validate($version);

            if ($report['errors'] !== []) {
                throw new BusinessRuleException('Workflow belum dapat dipublikasikan: '.implode(' ', array_slice($report['errors'], 0, 3)).(count($report['errors']) > 3 ? ' (+'.(count($report['errors']) - 3).' lainnya)' : ''));
            }

            $previous = WorkflowVersion::query()->where('workflow_id', $workflow->id)->where('status', WorkflowVersionStatus::Published->value)->first();
            $previous?->forceFill(['status' => WorkflowVersionStatus::Archived])->save();

            $version->forceFill([
                'status' => WorkflowVersionStatus::Published,
                'published_at' => Date::now(),
                'published_by' => $actor->id,
            ])->save();

            $this->registry->forget();

            $this->audit->record('workflow.version_published', 'Mempublikasikan '.$version->label().' workflow '.$workflow->name, $workflow,
                old: ['published_version' => $previous?->version_number],
                new: ['published_version' => $version->version_number],
                properties: ['warnings' => $report['warnings'] !== [] ? $report['warnings'] : null],
            );

            return $version;
        });
    }
}
