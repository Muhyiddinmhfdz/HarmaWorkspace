<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A state of a workflow version, backed by one status of the matching scope.
 * A record's current state is the state whose status equals its status_id.
 *
 * @property int $id
 * @property int $workflow_version_id
 * @property int $status_id
 * @property string $code
 * @property string|null $label_override
 * @property int $sort_order
 * @property bool $is_initial
 * @property bool $is_terminal
 * @property int|null $position_x
 * @property int|null $position_y
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'is_terminal' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Requires the status relation.
     */
    public function label(): string
    {
        return $this->label_override ?: $this->status->name;
    }
}
