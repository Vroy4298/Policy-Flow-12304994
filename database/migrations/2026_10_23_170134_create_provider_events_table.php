<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the provider_events table.
 *
 * This table records inbound events from the insurer (callbacks).
 *
 * The critical column is provider_event_id, which has a UNIQUE constraint.
 * When the insurer sends the same callback twice (DUPLICATE_CALLBACK scenario),
 * the second INSERT will fail with a unique constraint violation. We catch
 * that exception and return a 200 OK — processing is idempotent.
 *
 * This approach is called "insert-and-catch" idempotency and is more reliable
 * than "check-then-insert" because it prevents any race condition between the
 * check and the insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            // The insurer-assigned unique identifier for this event.
            // UNIQUE constraint prevents duplicate processing.
            $table->string('provider_event_id')->unique();

            // Type of event: ISSUED, FAILED, STATUS_UPDATE, etc.
            $table->string('event_type');

            // The policy number returned by the insurer (if the event is ISSUED)
            $table->string('policy_number')->nullable();

            // Provider reference included in the event
            $table->string('provider_reference')->nullable();

            // Full event payload stored for audit
            $table->json('event_payload');

            // Whether this event was actually processed or was a duplicate
            $table->boolean('was_duplicate')->default(false);

            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
