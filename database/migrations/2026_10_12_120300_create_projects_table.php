<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects are never hard-deleted: they are completed, cancelled or
     * archived, so every foreign key restricts deletion of what it references.
     * The lead department lives in project_departments (single source of truth).
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_status_id')->constrained('project_health_statuses')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('project_manager_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 20)->default('restricted');

            $table->date('planned_start');
            $table->date('planned_end');
            $table->date('current_start');
            $table->date('current_end');
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();

            $table->string('progress_mode', 20)->default('manual');
            $table->unsignedTinyInteger('progress_percentage')->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('name');
            $table->index(['archived_at', 'current_end']);
            $table->index(['project_manager_id', 'archived_at']);
            $table->index(['owner_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
