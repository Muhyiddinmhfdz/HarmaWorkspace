<?php

use App\Domain\Workflow\Actions\EnsureDefaultWorkflows;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Existing installations: build the default Project/Task/Ticket workflows
     * from the Phase 2–4 lifecycles (same transitions and permissions) and
     * bind every existing record to them. Statuses and history are untouched.
     * Fresh installations have no statuses yet; the SystemSeeder runs the same
     * idempotent action after seeding the masters.
     */
    public function up(): void
    {
        app(EnsureDefaultWorkflows::class)->handle();
    }
};
