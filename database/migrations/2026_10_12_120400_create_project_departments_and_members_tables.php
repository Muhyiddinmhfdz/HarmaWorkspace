<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-division structure and explicit project membership. Removing a
     * department or member stamps removed_at / ended_at (history is kept).
     * Generated marker columns let unique indexes enforce the active rules.
     */
    public function up(): void
    {
        Schema::create('project_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->string('function', 150)->nullable();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs('IF(removed_at IS NULL, 1, NULL)');
            $table->unsignedTinyInteger('lead_marker')->nullable()
                ->storedAs('IF(is_lead = 1 AND removed_at IS NULL, 1, NULL)');

            $table->unique(['project_id', 'department_id', 'active_marker'], 'project_departments_active_unique');
            $table->unique(['project_id', 'lead_marker'], 'project_departments_lead_unique');
            $table->index(['department_id', 'removed_at']);
            $table->index(['lead_user_id', 'removed_at']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_role_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('joined_at');
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs('IF(ended_at IS NULL, 1, NULL)');

            $table->unique(['project_id', 'user_id', 'active_marker'], 'project_members_active_unique');
            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('project_departments');
    }
};
