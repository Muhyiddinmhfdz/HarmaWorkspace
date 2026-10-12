<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Converts the provisional visibility values (members / internal) to the
     * final model (restricted / department / company). Anything unknown falls
     * back to the safe default "restricted"; nothing becomes company-wide by
     * accident except the former explicit "internal" choice.
     */
    public function up(): void
    {
        Schema::table('projects', function ($table) {
            $table->string('visibility', 20)->default('restricted')->change();
        });

        DB::table('projects')->where('visibility', 'internal')->update(['visibility' => 'company']);
        DB::table('projects')->whereNotIn('visibility', ['restricted', 'department', 'company'])->update(['visibility' => 'restricted']);
    }
};
