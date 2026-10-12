<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semantic flag for statuses that wait for a review decision (resolve or
     * revision). Used by ticket queues, dashboards and later by approvals.
     * Existing statuses default to false, so project/task behaviour is unchanged.
     */
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->boolean('awaits_review')->default(false)->after('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('awaits_review');
        });
    }
};
