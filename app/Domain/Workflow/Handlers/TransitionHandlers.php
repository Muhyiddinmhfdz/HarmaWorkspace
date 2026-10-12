<?php

namespace App\Domain\Workflow\Handlers;

use App\Enums\WorkflowEntity;

class TransitionHandlers
{
    public function for(WorkflowEntity $entity): TransitionHandler
    {
        return match ($entity) {
            WorkflowEntity::Project => app(ProjectTransitionHandler::class),
            WorkflowEntity::Task => app(TaskTransitionHandler::class),
            WorkflowEntity::Ticket => app(TicketTransitionHandler::class),
        };
    }
}
