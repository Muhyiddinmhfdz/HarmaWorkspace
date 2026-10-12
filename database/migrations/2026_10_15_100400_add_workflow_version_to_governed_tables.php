<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every project, task and ticket is bound to the workflow version it runs
     * on. Nullable at the database level so existing rows can be backfilled
     * by the next migration; actions always set it for new records.
     */
    public function up(): void
    {
        foreach (['projects', 'tasks', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('workflow_version_id')->nullable()->after('status_id')->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['projects', 'tasks', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('workflow_version_id');
            });
        }
    }
};
