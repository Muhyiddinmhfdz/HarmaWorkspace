<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Main PIC + collaborators with full history. Replacing or removing someone
     * stamps ended_at; rows are never deleted. Generated markers enforce one
     * active PIC per task and no duplicate active assignment per user.
     */
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
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

            $table->unique(['task_id', 'user_id', 'active_marker'], 'task_assignments_active_user_unique');
            $table->unique(['task_id', 'active_pic_marker'], 'task_assignments_active_pic_unique');
            $table->index(['user_id', 'ended_at', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
