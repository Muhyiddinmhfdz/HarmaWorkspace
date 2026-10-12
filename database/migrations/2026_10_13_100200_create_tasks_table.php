<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks may belong to a project or stand alone (operational tasks).
     * Never hard-deleted: tasks that will not be done are cancelled.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->restrictOnDelete();
            $table->foreignId('task_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('task_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('current_start')->nullable();
            $table->date('current_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();

            $table->decimal('estimated_hours', 7, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->unsignedTinyInteger('progress_percentage')->default(0);

            $table->text('blocked_reason')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status_id']);
            $table->index(['department_id', 'status_id']);
            $table->index(['status_id', 'current_end']);
            $table->index('title');
        });

        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_progress_range CHECK (progress_percentage BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_weight_positive CHECK (weight IS NULL OR weight > 0)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_estimate_non_negative CHECK (estimated_hours IS NULL OR estimated_hours >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
