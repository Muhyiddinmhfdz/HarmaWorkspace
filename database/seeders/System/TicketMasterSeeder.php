<?php

namespace Database\Seeders\System;

use App\Enums\MasterScope;
use App\Models\Priority;
use App\Models\Status;
use App\Models\TicketCategory;
use App\Models\TicketRejectionReason;
use App\Models\TicketRelationType;
use App\Models\TicketResolution;
use App\Models\TicketTransferReason;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Minimum production-safe ticket masters (ADR-065): everything ticket
 * creation and the lifecycle need — statuses, priorities, one neutral
 * Ticket Type and Category (GENERAL), resolutions, transfer/rejection
 * reasons and relation types.
 *
 * Records are created only when their code is missing (firstOrCreate):
 * names, colours, order and the active flag edited by an administrator are
 * never overwritten, and a deactivated default is not silently reactivated.
 * Optional catalogues (subcategories, sources) and richer examples are
 * organization data; demo values live in the DevelopmentSeeder.
 */
class TicketMasterSeeder extends Seeder
{
    public function run(): void
    {
        $ticket = MasterScope::Ticket->value;

        $statuses = [
            ['WAITING_ASSIGNMENT', 'Waiting Assignment', 'amber', ['is_initial' => true]],
            ['ASSIGNED', 'Assigned', 'sky', []],
            ['IN_PROGRESS', 'In Progress', 'blue', ['counts_as_started' => true]],
            ['NEED_INFO', 'Need Information', 'orange', ['is_blocked' => true]],
            ['IN_REVIEW', 'In Review', 'violet', ['counts_as_started' => true, 'awaits_review' => true]],
            ['REVISION', 'Revision', 'amber', ['counts_as_started' => true]],
            ['RESOLVED', 'Resolved', 'emerald', ['counts_as_completed' => true]],
            ['CLOSED', 'Closed', 'green', ['is_terminal' => true, 'counts_as_completed' => true]],
            ['REJECTED', 'Rejected', 'red', ['is_terminal' => true]],
            ['CANCELLED', 'Cancelled', 'zinc', ['is_terminal' => true]],
            ['REOPENED', 'Reopened', 'pink', []],
        ];

        foreach ($statuses as $index => [$code, $name, $color, $flags]) {
            Status::query()->firstOrCreate(
                ['scope' => $ticket, 'code' => $code],
                ['name' => $name, 'color' => $color, 'sort_order' => ($index + 1) * 10, ...$flags],
            );
        }

        foreach ([['LOW', 'Low', 10, 'zinc'], ['NORMAL', 'Normal', 20, 'sky'], ['HIGH', 'High', 30, 'orange'], ['CRITICAL', 'Critical', 40, 'red']] as $index => [$code, $name, $level, $color]) {
            Priority::query()->firstOrCreate(
                ['scope' => $ticket, 'code' => $code],
                ['name' => $name, 'level' => $level, 'color' => $color, 'sort_order' => ($index + 1) * 10],
            );
        }

        $this->master(TicketType::class, [
            ['GENERAL', 'General', ['color' => 'zinc', 'description' => 'Jenis umum untuk request yang belum dikategorikan khusus.']],
        ]);

        $this->master(TicketCategory::class, [
            ['GENERAL', 'General', ['description' => 'Kategori umum. Tambahkan kategori lain sesuai kebutuhan organisasi.']],
        ]);

        $this->master(TicketResolution::class, [
            ['FIXED', 'Fixed', ['color' => 'green']],
            ['ANSWERED', 'Answered', ['color' => 'sky']],
            ['WORKAROUND', 'Workaround Provided', ['color' => 'amber']],
            ['DUPLICATE', 'Duplicate', ['color' => 'zinc', 'requires_related_ticket' => true]],
            ['NOT_REPRODUCIBLE', 'Not Reproducible', ['color' => 'zinc']],
            ['NOT_REQUIRED', 'Not Required', ['color' => 'zinc']],
            ['COMPLETED', 'Completed', ['color' => 'green']],
        ]);

        $this->master(TicketTransferReason::class, [
            ['WRONG_DEPARTMENT', 'Wrong Department', []],
            ['DIFFERENT_EXPERTISE', 'Requires Different Expertise', []],
            ['ESCALATION', 'Escalation', []],
            ['OWNERSHIP_CHANGED', 'Work Ownership Changed', []],
            ['OTHER', 'Other', []],
        ]);

        $this->master(TicketRejectionReason::class, [
            ['INVALID', 'Invalid Request', []],
            ['DUPLICATE', 'Duplicate', []],
            ['INSUFFICIENT_INFO', 'Insufficient Information', []],
            ['OUT_OF_SCOPE', 'Out of Scope', []],
            ['WRONG_PROCESS', 'Wrong Process', []],
            ['OTHER', 'Other', []],
        ]);

        $this->master(TicketRelationType::class, [
            ['RELATED_TO', 'Related To', []],
            ['DUPLICATE_OF', 'Duplicate Of', ['is_duplicate' => true]],
            ['BLOCKS', 'Blocks', []],
            ['PARENT_OF', 'Parent Of', []],
            ['CHILD_OF', 'Child Of', []],
        ]);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $rows
     */
    private function master(string $model, array $rows): void
    {
        foreach ($rows as $index => [$code, $name, $attributes]) {
            $model::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => ($index + 1) * 10, ...$attributes]);
        }
    }
}
