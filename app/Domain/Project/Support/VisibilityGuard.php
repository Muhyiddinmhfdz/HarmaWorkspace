<?php

namespace App\Domain\Project\Support;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\ProjectVisibility;
use App\Models\User;

/**
 * Company-wide visibility is an administrative decision: it needs
 * `project.manage-visibility`, even for the project manager.
 */
class VisibilityGuard
{
    public function canSet(ProjectVisibility $visibility, User $actor): bool
    {
        return ! $visibility->requiresAdministrativePermission() || $actor->can('project.manage-visibility');
    }

    public function ensureCanSet(ProjectVisibility $visibility, User $actor): void
    {
        if (! $this->canSet($visibility, $actor)) {
            throw new BusinessRuleException('Anda tidak memiliki izin untuk membuka project ke seluruh perusahaan (visibility Company).');
        }
    }
}
