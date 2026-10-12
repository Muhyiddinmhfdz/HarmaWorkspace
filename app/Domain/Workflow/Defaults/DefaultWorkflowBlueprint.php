<?php

namespace App\Domain\Workflow\Defaults;

use App\Domain\Workflow\Support\WorkflowActorResolver as Roles;
use App\Enums\WorkflowEntity;
use App\Enums\WorkflowRuleType;
use App\Models\Status;
use Illuminate\Support\Collection;

/**
 * The default workflows, generated from the Phase 2–4 lifecycles so that
 * moving to the Workflow Engine changes no behaviour (ADR-066):
 *
 * - the same transitions (by stable status code; administrator-defined
 *   project/task statuses keep the old flag-based fallback);
 * - the same "who": the policy abilities that guarded each transition are
 *   expressed as contextual-role + permission rules;
 * - the same requirements (blocked reason, information messages, revision,
 *   reopen and cancel reasons, resolution).
 *
 * This is the only place the former lifecycle maps live; at runtime the
 * published workflow version is the source of truth.
 */
class DefaultWorkflowBlueprint
{
    public const array CODES = [
        'project' => 'DEFAULT_PROJECT',
        'task' => 'DEFAULT_TASK',
        'ticket' => 'DEFAULT_TICKET',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array PROJECT = [
        'DRAFT' => ['PLANNING', 'CANCELLED'],
        'PLANNING' => ['ACTIVE', 'ON_HOLD', 'CANCELLED'],
        'ACTIVE' => ['ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TASK = [
        'BACKLOG' => ['TODO', 'CANCELLED'],
        'TODO' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['BLOCKED', 'IN_REVIEW', 'CANCELLED'],
        'BLOCKED' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_REVIEW' => ['DONE', 'REVISION'],
        'REVISION' => ['IN_PROGRESS', 'IN_REVIEW', 'CANCELLED'],
        'DONE' => ['REVISION'],
        'CANCELLED' => ['TODO'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TICKET = [
        'WAITING_ASSIGNMENT' => ['ASSIGNED', 'NEED_INFO', 'REJECTED', 'CANCELLED'],
        'ASSIGNED' => ['IN_PROGRESS', 'NEED_INFO', 'CANCELLED'],
        'IN_PROGRESS' => ['NEED_INFO', 'IN_REVIEW', 'CANCELLED'],
        'NEED_INFO' => ['CANCELLED'],
        'IN_REVIEW' => ['RESOLVED', 'REVISION', 'NEED_INFO'],
        'REVISION' => ['IN_PROGRESS', 'IN_REVIEW'],
        'RESOLVED' => ['CLOSED', 'REOPENED'],
        'CLOSED' => ['REOPENED'],
        'REOPENED' => ['ASSIGNED', 'IN_PROGRESS'],
        'REJECTED' => [],
        'CANCELLED' => [],
    ];

    /**
     * Ticket target status code => domain operation.
     *
     * @var array<string, string>
     */
    private const array TICKET_OPERATIONS = [
        'ASSIGNED' => 'assign',
        'IN_PROGRESS' => 'start',
        'NEED_INFO' => 'request_information',
        'IN_REVIEW' => 'send_to_review',
        'REVISION' => 'request_revision',
        'RESOLVED' => 'resolve',
        'CLOSED' => 'close',
        'REOPENED' => 'reopen',
        'REJECTED' => 'reject',
        'CANCELLED' => 'cancel',
    ];

    /**
     * @var array<string, string>
     */
    private const array TASK_LABELS = [
        '*>TODO' => 'Move to To Do',
        'CANCELLED>TODO' => 'Reopen task',
        '*>IN_PROGRESS' => 'Start work',
        'BLOCKED>IN_PROGRESS' => 'Resume task',
        'REVISION>IN_PROGRESS' => 'Continue work',
        '*>BLOCKED' => 'Mark as blocked',
        '*>IN_REVIEW' => 'Send to review',
        '*>DONE' => 'Mark as done',
        '*>REVISION' => 'Request revision',
        'DONE>REVISION' => 'Reopen for revision',
        '*>CANCELLED' => 'Cancel task',
    ];

    /**
     * @var array<string, string>
     */
    private const array PROJECT_LABELS = [
        '*>PLANNING' => 'Move to planning',
        'PLANNING>ACTIVE' => 'Start project',
        'ON_HOLD>ACTIVE' => 'Resume project',
        '*>ON_HOLD' => 'Put on hold',
        '*>CANCELLED' => 'Cancel project',
        '*>COMPLETED' => 'Complete project',
    ];

    /**
     * Transitions for the given statuses of the entity's scope.
     *
     * @param  Collection<int, Status>  $statuses
     * @return list<array{from: Status, to: Status, operation: string, label: string, rules: array<string, array<string, list<string>>|null>}>
     */
    public function transitions(WorkflowEntity $entity, Collection $statuses): array
    {
        $transitions = [];

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from->id === $to->id || ! $to->is_active || ! $this->allows($entity, $from, $to)) {
                    continue;
                }

                $transitions[] = match ($entity) {
                    WorkflowEntity::Project => $this->project($from, $to),
                    WorkflowEntity::Task => $this->task($from, $to),
                    WorkflowEntity::Ticket => $this->ticket($from, $to),
                };
            }
        }

        if ($entity === WorkflowEntity::Ticket) {
            // Need Information returns to the status it was requested from.
            foreach ($transitions as $transition) {
                if ($transition['operation'] === 'request_information') {
                    $transitions[] = [
                        'from' => $transition['to'],
                        'to' => $transition['from'],
                        'operation' => 'provide_information',
                        'label' => 'Provide Information',
                        'rules' => ['context_role' => ['roles' => [Roles::REQUESTER, Roles::DEPARTMENT_LEADER]], 'permission' => ['permissions' => ['ticket.update']], 'comment_required' => null],
                    ];
                }
            }
        }

        return $transitions;
    }

    private function allows(WorkflowEntity $entity, Status $from, Status $to): bool
    {
        $map = match ($entity) {
            WorkflowEntity::Project => self::PROJECT,
            WorkflowEntity::Task => self::TASK,
            WorkflowEntity::Ticket => self::TICKET,
        };

        if (array_key_exists($from->code, $map)) {
            return in_array($to->code, $map[$from->code], true);
        }

        // Administrator-defined project/task statuses kept the flag-based
        // fallback; tickets only ever moved along known codes.
        return $entity !== WorkflowEntity::Ticket && ! $from->is_terminal && ! $to->is_initial;
    }

    /**
     * @return array{from: Status, to: Status, operation: string, label: string, rules: array<string, array<string, list<string>>|null>}
     */
    private function project(Status $from, Status $to): array
    {
        $permission = $to->is_terminal ? 'project.complete' : 'project.update';

        return [
            'from' => $from,
            'to' => $to,
            'operation' => $to->counts_as_completed ? 'complete' : 'move',
            'label' => self::PROJECT_LABELS[$from->code.'>'.$to->code]
                ?? self::PROJECT_LABELS['*>'.$to->code]
                ?? ($to->is_terminal && ! $to->counts_as_completed ? 'Cancel project' : 'Move to '.$to->name),
            'rules' => [
                'context_role' => ['roles' => [Roles::PROJECT_MANAGER]],
                'permission' => ['permissions' => [$permission]],
            ],
        ];
    }

    /**
     * Phase 3 abilities: finishing or reopening needs "complete", cancelling
     * "cancel", everything else "work" (the Main PIC may work but never
     * approve their own task).
     *
     * @return array{from: Status, to: Status, operation: string, label: string, rules: array<string, array<string, list<string>>|null>}
     */
    private function task(Status $from, Status $to): array
    {
        $managers = [Roles::DEPARTMENT_LEADER, Roles::PROJECT_MANAGER, Roles::STANDALONE_CREATOR];

        [$roles, $permission] = match (true) {
            $from->is_terminal, $to->counts_as_completed => [$managers, 'task.complete'],
            $to->is_terminal => [$managers, 'task.cancel'],
            default => [[Roles::MAIN_PIC, ...$managers], 'task.manage-progress'],
        };

        $rules = [
            'context_role' => ['roles' => $roles],
            'permission' => ['permissions' => [$permission]],
        ];

        if ($to->is_blocked) {
            $rules['reason_required'] = null;
        }

        return [
            'from' => $from,
            'to' => $to,
            'operation' => 'move',
            'label' => self::TASK_LABELS[$from->code.'>'.$to->code] ?? self::TASK_LABELS['*>'.$to->code] ?? 'Move to '.$to->name,
            'rules' => $rules,
        ];
    }

    /**
     * Phase 4 abilities per operation (TicketPolicy).
     *
     * @return array{from: Status, to: Status, operation: string, label: string, rules: array<string, array<string, list<string>>|null>}
     */
    private function ticket(Status $from, Status $to): array
    {
        $operation = self::TICKET_OPERATIONS[$to->code] ?? 'move';

        [$roles, $permissions, $requirements] = match ($operation) {
            'assign' => [[Roles::DEPARTMENT_LEADER], ['ticket.assign'], []],
            'start', 'send_to_review' => [[Roles::MAIN_PIC, Roles::DEPARTMENT_LEADER], ['ticket.manage-inbox'], []],
            'request_information' => [[Roles::MAIN_PIC, Roles::DEPARTMENT_LEADER, Roles::REQUESTER_REVIEWER], ['ticket.manage-inbox', 'ticket.review'], ['comment_required']],
            'request_revision' => [[Roles::DEPARTMENT_LEADER, Roles::REQUESTER_REVIEWER], ['ticket.review'], ['reason_required']],
            'resolve' => [[Roles::DEPARTMENT_LEADER, Roles::REQUESTER_REVIEWER], ['ticket.review'], ['resolution_required']],
            'close' => [[Roles::REQUESTER, Roles::DEPARTMENT_LEADER], ['ticket.close'], []],
            'reopen' => [[Roles::REQUESTER, Roles::DEPARTMENT_LEADER], ['ticket.close'], ['reason_required']],
            'reject' => [[Roles::DEPARTMENT_LEADER], ['ticket.manage-inbox'], ['reason_required']],
            'cancel' => [[Roles::REQUESTER, Roles::DEPARTMENT_LEADER], ['ticket.cancel'], ['reason_required']],
            default => [[Roles::MAIN_PIC, Roles::DEPARTMENT_LEADER], ['ticket.manage-inbox'], []],
        };

        $rules = [
            'context_role' => ['roles' => $roles],
            'permission' => ['permissions' => $permissions],
        ];

        foreach ($requirements as $requirement) {
            $rules[$requirement] = null;
        }

        $label = match ($operation) {
            'assign' => 'Assign PIC',
            'start' => $from->counts_as_started ? 'Continue Work' : 'Start Work',
            'request_information' => 'Request Information',
            'send_to_review' => 'Send to Review',
            'request_revision' => 'Request Revision',
            'resolve' => 'Resolve',
            'close' => 'Close Report',
            'reopen' => 'Reopen',
            'reject' => 'Reject Report',
            'cancel' => 'Cancel Report',
            default => 'Move to '.$to->name,
        };

        return ['from' => $from, 'to' => $to, 'operation' => $operation, 'label' => $label, 'rules' => $rules];
    }

    /**
     * Type-safe rule list from the blueprint shape.
     *
     * @param  array<string, array<string, list<string>>|null>  $rules
     * @return array<string, array{type: WorkflowRuleType, config: array<string, list<string>>|null}>
     */
    public static function typedRules(array $rules): array
    {
        $typed = [];

        foreach ($rules as $type => $config) {
            $typed[$type] = ['type' => WorkflowRuleType::from($type), 'config' => $config];
        }

        return $typed;
    }
}
