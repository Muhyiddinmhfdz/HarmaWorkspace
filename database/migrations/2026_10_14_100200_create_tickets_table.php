<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tickets: requests handled by one current department. Standalone or
     * related to a project and/or task. Never hard-deleted.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('title', 200);
            $table->text('description');

            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_subcategory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_source_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_department_id')->constrained('departments')->restrictOnDelete();

            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamp('planned_due_at')->nullable();
            $table->timestamp('current_due_at')->nullable();

            $table->timestamp('first_assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();

            $table->foreignId('resume_status_id')->nullable()->constrained('statuses')->restrictOnDelete();

            $table->foreignId('resolution_id')->nullable()->constrained('ticket_resolutions')->restrictOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('rejection_reason_id')->nullable()->constrained('ticket_rejection_reasons')->restrictOnDelete();
            $table->text('rejection_notes')->nullable();

            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestamp('last_reopened_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['current_department_id', 'status_id']);
            $table->index(['current_department_id', 'accepted_at']);
            $table->index(['status_id', 'current_due_at']);
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
