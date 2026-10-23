<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the policy_state_histories table.
 *
 * Every time a policy_request changes its status, we insert one row here.
 * This gives reviewers and interviewers a complete audit trail of:
 *   - Which state did the request move from?
 *   - Which state did it move to?
 *   - When did that happen?
 *   - Why did it happen (reason/context)?
 *
 * This is append-only — we never update or delete history rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            $table->string('from_status')->nullable(); // null for initial RECEIVED
            $table->string('to_status');
            $table->text('reason')->nullable(); // human-readable explanation
            $table->json('context')->nullable();  // additional structured data

            $table->timestamp('transitioned_at');

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_histories');
    }
};
