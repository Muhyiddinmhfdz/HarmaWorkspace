<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document number counters. Rows are locked (SELECT ... FOR UPDATE) while a
     * number is issued, and `last_number` never decreases, so numbers are never
     * reused.
     */
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 30);
            $table->string('period', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
