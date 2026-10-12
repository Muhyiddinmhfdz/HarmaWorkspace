<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assignment and department transfer history. Rows are never deleted:
     * replacing someone stamps ended_at; a transfer is an append-only row.
     */
    public function up(): void
    {
        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->timestamp('assigned_at');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs('IF(ended_at IS NULL, 1, NULL)');
            $table->unsignedTinyInteger('active_pic_marker')->nullable()
                ->storedAs("IF(role = 'pic' AND ended_at IS NULL, 1, NULL)");

            $table->unique(['ticket_id', 'user_id', 'active_marker'], 'ticket_assignments_active_user_unique');
            $table->unique(['ticket_id', 'active_pic_marker'], 'ticket_assignments_active_pic_unique');
            $table->index(['user_id', 'ended_at', 'role']);
        });

        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('transfer_reason_id')->constrained('ticket_transfer_reasons')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('transferred_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->index(['to_department_id', 'transferred_at']);
        });

        Schema::create('ticket_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('related_ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('ticket_relation_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'related_ticket_id']);
        });

        DB::statement('ALTER TABLE ticket_relations ADD CONSTRAINT ticket_relations_not_self CHECK (ticket_id <> related_ticket_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_relations');
        Schema::dropIfExists('ticket_transfers');
        Schema::dropIfExists('ticket_assignments');
    }
};
