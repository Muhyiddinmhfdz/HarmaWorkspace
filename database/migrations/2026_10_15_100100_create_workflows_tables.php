<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic workflow definitions: workflow → versions → states (statuses)
     * → transitions → rules. Published versions are immutable; records keep
     * the version they started with.
     */
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 20);
            $table->string('code', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // At most one default workflow per entity type.
            $table->unsignedTinyInteger('default_marker')->nullable()->storedAs('IF(is_default, 1, NULL)');

            $table->unique(['entity_type', 'code']);
            $table->unique(['entity_type', 'default_marker'], 'workflows_one_default_per_entity');
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One current published version and one open draft per workflow.
            $table->unsignedTinyInteger('published_marker')->nullable()->storedAs("IF(status = 'published', 1, NULL)");
            $table->unsignedTinyInteger('draft_marker')->nullable()->storedAs("IF(status = 'draft', 1, NULL)");

            $table->unique(['workflow_id', 'version_number']);
            $table->unique(['workflow_id', 'published_marker'], 'workflow_versions_one_published');
            $table->unique(['workflow_id', 'draft_marker'], 'workflow_versions_one_draft');
            $table->index(['workflow_id', 'status']);
        });

        Schema::create('workflow_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label_override', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->timestamps();

            $table->unsignedTinyInteger('initial_marker')->nullable()->storedAs('IF(is_initial, 1, NULL)');

            $table->unique(['workflow_version_id', 'code']);
            $table->unique(['workflow_version_id', 'status_id']);
            $table->unique(['workflow_version_id', 'initial_marker'], 'workflow_states_one_initial');
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_state_id')->constrained('workflow_states')->restrictOnDelete();
            $table->foreignId('to_state_id')->constrained('workflow_states')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('action_label', 100);
            $table->string('operation', 40)->default('move');
            $table->string('confirmation_message', 500)->nullable();
            $table->foreignId('approval_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['workflow_version_id', 'code']);
            $table->unique(['workflow_version_id', 'from_state_id', 'to_state_id'], 'workflow_transitions_unique_path');
            $table->index(['workflow_version_id', 'from_state_id']);
        });

        Schema::create('workflow_transition_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_transition_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['workflow_transition_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_rules');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
