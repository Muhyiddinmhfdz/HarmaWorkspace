<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable approval templates (sequential steps). Requests snapshot the
     * steps, so editing a template only affects new requests.
     */
    public function up(): void
    {
        Schema::create('approval_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('entity_type', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'is_active']);
        });

        Schema::create('approval_template_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name', 120);
            $table->string('approver_type', 30);
            $table->json('approver_config')->nullable();
            $table->unsignedSmallInteger('minimum_approvals')->default(1);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['approval_template_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_template_steps');
        Schema::dropIfExists('approval_templates');
    }
};
