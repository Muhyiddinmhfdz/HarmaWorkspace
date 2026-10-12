<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable technical record of every executed state change (the
     * human-readable story stays in the activity log). No FK on the subject
     * (polymorphic); version/transition/state FKs restrict deletion of any
     * definition that history refers to.
     */
    public function up(): void
    {
        Schema::create('workflow_transition_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_transition_id')->nullable()->constrained()->restrictOnDelete();
            $table->morphs('subject');
            $table->foreignId('from_state_id')->nullable()->constrained('workflow_states')->restrictOnDelete();
            $table->foreignId('to_state_id')->constrained('workflow_states')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_histories');
    }
};
