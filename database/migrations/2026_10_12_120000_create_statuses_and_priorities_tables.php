<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared status and priority masters, partitioned by `scope`
     * (project / task / ticket). See ADR-012 and blueprint §9.2.
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('code', 30);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('color', 20)->default('zinc');
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('counts_as_completed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['scope', 'code']);
            $table->index(['scope', 'is_active', 'sort_order']);
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('code', 30);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('level')->default(0);
            $table->string('color', 20)->default('zinc');
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['scope', 'code']);
            $table->index(['scope', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('statuses');
    }
};
