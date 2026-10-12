<?php

namespace App\Domain\MasterData;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Enums\MasterScope;
use App\Models\Priority;
use App\Models\Status;

/**
 * Central scope validation for the shared status/priority masters. A foreign
 * key to `statuses.id` alone cannot stop a project from using a task status,
 * so every write path resolves statuses and priorities through this guard.
 */
class ScopedMasterGuard
{
    /**
     * @param  bool  $requireActive  New selections must be active; an unchanged value may stay inactive.
     */
    public function status(int $id, MasterScope $scope, bool $requireActive = true): Status
    {
        $status = Status::query()->find($id);

        if ($status === null || $status->scope !== $scope) {
            throw new BusinessRuleException('Status yang dipilih tidak berlaku untuk '.$scope->name.'.');
        }

        if ($requireActive && ! $status->is_active) {
            throw new BusinessRuleException('Status '.$status->name.' sedang nonaktif dan tidak dapat dipilih.');
        }

        return $status;
    }

    public function priority(int $id, MasterScope $scope, bool $requireActive = true): Priority
    {
        $priority = Priority::query()->find($id);

        if ($priority === null || $priority->scope !== $scope) {
            throw new BusinessRuleException('Priority yang dipilih tidak berlaku untuk '.$scope->name.'.');
        }

        if ($requireActive && ! $priority->is_active) {
            throw new BusinessRuleException('Priority '.$priority->name.' sedang nonaktif dan tidak dapat dipilih.');
        }

        return $priority;
    }

    public function initialStatus(MasterScope $scope): Status
    {
        return Status::query()->forScope($scope)->active()->where('is_initial', true)->orderBy('sort_order')->first()
            ?? throw new BusinessRuleException('Belum ada status awal (initial) untuk '.$scope->name.'. Atur di Master Data.');
    }
}
