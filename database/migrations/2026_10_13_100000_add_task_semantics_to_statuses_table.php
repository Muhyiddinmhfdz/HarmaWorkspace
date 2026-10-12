<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semantic flags used by task logic (never the display name):
     * - counts_as_started: work has begun (sets actual start);
     * - is_blocked: work is blocked (reason required, counted as blocked).
     * Existing statuses default to false, so project behaviour is unchanged.
     */
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->boolean('counts_as_started')->default(false)->after('counts_as_completed');
            $table->boolean('is_blocked')->default(false)->after('counts_as_started');
        });
    }

    public function down(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn(['counts_as_started', 'is_blocked']);
        });
    }
};
