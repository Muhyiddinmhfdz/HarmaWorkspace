<?php

namespace App\Domain\Approval\Support;

use App\Domain\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Approval activity is written on the approved record (subject), so the
 * project/task/ticket timeline tells the story ("Rina menyetujui ...");
 * the request id keeps the technical link for the audit log.
 */
class ApprovalAudit
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(ApprovalRequest $request, Model $approvable, string $event, string $description, array $properties = []): void
    {
        $this->audit->record(
            event: $event,
            description: $description,
            subject: $approvable,
            properties: array_filter([
                'approval_request_id' => $request->id,
                'approval' => $request->title,
                'project_id' => $approvable->getAttribute('project_id'),
                'task_id' => $approvable->getAttribute('task_id'),
                ...$properties,
            ], fn ($value) => $value !== null),
        );
    }
}
