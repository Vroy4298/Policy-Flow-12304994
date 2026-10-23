<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MockInsurerPolicy — the mock insurer's own internal record of a policy it issued.
 *
 * This table belongs conceptually to the mock insurer service, not the main application.
 * It simulates what a real insurer would store in their own database.
 *
 * Key use case: TIMEOUT_AFTER_ISSUE scenario.
 * The mock insurer creates a row here to "confirm" it issued the policy,
 * but then does NOT return the response to the caller.
 * When reconciliation queries this table by provider_reference, it finds the
 * policy_number and updates the main PolicyRequest without issuing again.
 */
class MockInsurerPolicy extends Model
{
    protected $fillable = [
        'provider_reference',
        'policy_number',
        'scenario',
        'response_delivered',
    ];

    protected $casts = [
        'response_delivered' => 'boolean',
    ];
}
