<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Published versions are immutable (ADR-067): changes start as a new draft
 * copied from the current (or latest) version. One open draft per workflow.
 */
class CreateDraftVersion
{
    public function __construct(
        private CloneWorkflowVersion $clone,
        private AuditLogger $audit,
    ) {}

    public function handle(Workflow $workflow, User $actor): WorkflowVersion
    {
        return DB::transaction(function () use ($workflow, $actor): WorkflowVersion {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if ($workflow->draftVersion()->exists()) {
                throw new BusinessRuleException('Workflow ini sudah memiliki draft. Lanjutkan draft yang ada.');
            }

            $source = $workflow->publishedVersion()->first() ?? $workflow->versions()->first();
            $draft = $this->clone->handle($source, $workflow, $actor);

            $this->audit->record('workflow.draft_created', 'Membuat draft '.$draft->label().' untuk workflow '.$workflow->name, $workflow, properties: ['version' => $draft->version_number, 'copied_from' => $source?->version_number]);

            return $draft;
        });
    }
}
