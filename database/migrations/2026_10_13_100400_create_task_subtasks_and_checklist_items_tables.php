<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One level below a task by construction (separate table, no parent_id).
         */
        Schema::create('task_subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'sort_order']);
        });

        /*
         * Lightweight checklist owned by a task or a subtask.
         */
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('checklistable');
            $table->string('text', 500);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('task_subtasks');
    }
};
