<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership history: ending a membership sets `ended_at` instead of deleting the row.
     *
     * MySQL allows many NULLs in a unique index, so the generated marker columns
     * enforce "one active membership per user/department" and "one active primary
     * department per user" at the database level.
     */
    public function up(): void
    {
        Schema::create('department_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_leader')->default(false);
            $table->date('joined_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs('IF(ended_at IS NULL, 1, NULL)');
            $table->unsignedTinyInteger('active_primary_marker')->nullable()
                ->storedAs('IF(is_primary = 1 AND ended_at IS NULL, 1, NULL)');

            $table->unique(['user_id', 'department_id', 'active_marker'], 'department_memberships_active_unique');
            $table->unique(['user_id', 'active_primary_marker'], 'department_memberships_primary_unique');
            $table->index(['department_id', 'ended_at', 'is_leader']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_memberships');
    }
};
