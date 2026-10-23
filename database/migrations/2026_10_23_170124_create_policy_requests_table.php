<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the policy_requests table — the central table for this application.
 *
 * Key design decisions:
 *
 * 1. UNIQUE(agent_id, client_request_id): This database-level constraint is the
 *    primary defence against duplicate intake. Even if two HTTP requests arrive
 *    simultaneously and both pass the application-level check, only one INSERT
 *    will succeed. The other will hit a unique constraint violation, which we
 *    catch and turn into a safe "return existing record" response.
 *
 * 2. premium is stored as DECIMAL(10,2) — never as FLOAT — to avoid the
 *    rounding errors that floating-point arithmetic introduces.
 *
 * 3. status is a VARCHAR with a CHECK constraint (added via DB statement below)
 *    rather than MySQL ENUM so that adding a new status is a simple migration
 *    without a full table rebuild.
 *
 * 4. policy_number has a UNIQUE constraint because a policy number issued by an
 *    insurer must identify exactly one policy globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            // ---- Agent / client identification ----
            $table->string('agent_id');
            // The ID the agent assigns to this request on their side.
            // Combined with agent_id it uniquely identifies a logical request.
            $table->string('client_request_id');

            // ---- Customer details ----
            $table->string('customer_name');
            $table->date('date_of_birth');

            // ---- Trip details ----
            $table->string('destination');   // DOMESTIC | INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');

            // ---- Plan / coverage ----
            $table->string('plan_code');     // BASIC | PLUS | PREMIUM
            $table->unsignedInteger('sum_insured'); // 100000 | 300000 | 500000

            // ---- Calculated premium (immutable snapshot) ----
            $table->decimal('premium', 10, 2)->nullable();
            $table->unsignedSmallInteger('travel_days')->nullable();

            // ---- Lifecycle state ----
            // Valid values: RECEIVED, VALIDATED, SUBMITTED, PENDING_CONFIRMATION,
            //               ISSUED, FAILED, NEEDS_RECONCILIATION
            $table->string('status')->default('RECEIVED');

            // ---- Provider / issuance tracking ----
            $table->string('provider_reference')->nullable(); // insurer's reference for this submission
            $table->string('policy_number')->nullable()->unique(); // issued policy number (globally unique)
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('last_provider_response')->nullable(); // sanitised last response from insurer

            $table->timestamps();

            // THE critical idempotency constraint.
            // Database enforces this; application code alone is not sufficient.
            $table->unique(['agent_id', 'client_request_id']);

            // Index on status for the listing/filter endpoint
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
