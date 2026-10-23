<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the mock_insurer_policies table.
 *
 * This table is the "internal database" of our mock insurer service.
 * It simulates what a real insurer would store on their side.
 *
 * This is essential for the TIMEOUT_AFTER_ISSUE scenario:
 *   - The mock insurer creates a row here (meaning it issued a policy)
 *   - But it doesn't return the response to the caller (simulating a network timeout)
 *   - When reconciliation runs, it queries this table to discover the policy number
 *   - This prevents a second issuance
 *
 * In a real system, this would be a call to the insurer's "status check" API.
 * Here we read from this table directly.
 *
 * Important: provider_reference has a UNIQUE constraint. This ensures the mock
 * insurer never creates two policies for the same provider reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();

            // The provider_reference we assigned when submitting
            $table->string('provider_reference')->unique();

            // The policy number the mock insurer "generated"
            $table->string('policy_number')->unique();

            // The scenario that was simulated
            $table->string('scenario');

            // Whether the response was delivered to the caller (false = timeout occurred)
            $table->boolean('response_delivered')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
