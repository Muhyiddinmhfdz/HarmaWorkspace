<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable ticket masters (managed in the Master Data Center).
     * Deactivated instead of deleted once referenced.
     */
    public function up(): void
    {
        $master = function (Blueprint $table, bool $withColor = false): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();

            if ($withColor) {
                $table->string('color', 20)->default('zinc');
            }

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        };

        Schema::create('ticket_types', fn (Blueprint $table) => $master($table, true));
        Schema::create('ticket_categories', fn (Blueprint $table) => $master($table));
        Schema::create('ticket_sources', fn (Blueprint $table) => $master($table));
        Schema::create('ticket_transfer_reasons', fn (Blueprint $table) => $master($table));
        Schema::create('ticket_rejection_reasons', fn (Blueprint $table) => $master($table));

        Schema::create('ticket_subcategories', function (Blueprint $table) use ($master) {
            $master($table);
            $table->foreignId('ticket_category_id')->constrained()->restrictOnDelete();
        });

        Schema::create('ticket_resolutions', function (Blueprint $table) use ($master) {
            $master($table, true);
            $table->boolean('requires_related_ticket')->default(false);
        });

        Schema::create('ticket_relation_types', function (Blueprint $table) use ($master) {
            $master($table);
            $table->boolean('is_duplicate')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['ticket_relation_types', 'ticket_resolutions', 'ticket_subcategories', 'ticket_rejection_reasons', 'ticket_transfer_reasons', 'ticket_sources', 'ticket_categories', 'ticket_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
