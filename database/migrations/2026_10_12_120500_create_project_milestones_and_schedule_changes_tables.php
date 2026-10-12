<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_key_milestone')->default(false);
            $table->date('planned_date');
            $table->date('current_due_date');
            $table->date('actual_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'actual_date', 'current_due_date']);
        });

        /*
         * Append-only schedule history shared by schedulable entities
         * (project, milestone; task in Phase 3).
         */
        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->morphs('schedulable');
            $table->date('old_start')->nullable();
            $table->date('old_end')->nullable();
            $table->date('new_start')->nullable();
            $table->date('new_end')->nullable();
            $table->text('reason');
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('project_milestones');
    }
};
