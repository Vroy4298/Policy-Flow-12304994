<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the issuance_attempts table.
 *
 * Every time we call the (mock) insurer's issuance API, we record one row here.
 * This gives us:
 *   - A full audit trail of every attempt.
 *   - A way to check whether a submission is already in-flight before retrying.
 *   - A place to store the scenario used for the mock, which helps tests reproduce
 *     specific behaviours.
 *
 * The scenario column is for development/testing only and should not be exposed
 * in production APIs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            // Which attempt number this is (1, 2, 3 …)
            $table->unsignedSmallInteger('attempt_number');

            // Status of this particular attempt: PENDING, SUCCESS, FAILED, TIMEOUT
            $table->string('attempt_status');

            // Provider-assigned reference for this submission (may be null on failure)
            $table->string('provider_reference')->nullable();

            // The scenario that was active for this attempt (dev/test only)
            $table->string('scenario')->nullable();

            // Raw (sanitised) response from the insurer
            $table->json('response_payload')->nullable();

            // Error message if this attempt failed
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
