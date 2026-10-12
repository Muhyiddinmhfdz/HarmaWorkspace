<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval runtime: a request on any approvable record, its snapshotted
     * steps and immutable decisions. A generated marker prevents two pending
     * requests for the same transition on the same record.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('approval_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('workflow_transition_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('current_step_order')->nullable();
            $table->json('input')->nullable();
            $table->string('outcome_note', 500)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unsignedTinyInteger('pending_marker')->nullable()->storedAs("IF(status = 'pending', 1, NULL)");
            $table->unsignedBigInteger('transition_marker')->storedAs('COALESCE(workflow_transition_id, 0)');

            $table->unique(['approvable_type', 'approvable_id', 'transition_marker', 'pending_marker'], 'approval_requests_one_pending');
            $table->index(['status', 'requested_at']);
            $table->index(['requested_by', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name', 120);
            $table->string('approver_type', 30);
            $table->json('approver_context')->nullable();
            $table->unsignedSmallInteger('minimum_approvals')->default(1);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_required')->default(true);
            $table->string('status', 30)->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_request_id', 'step_order']);
            $table->index('status');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_step_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');

            $table->unique(['approval_step_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
    }
};
