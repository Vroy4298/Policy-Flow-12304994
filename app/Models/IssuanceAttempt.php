<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IssuanceAttempt — records each call we make to the insurer's issuance API.
 *
 * A policy_request can have multiple attempts if:
 *  - The first attempt resulted in TEMPORARY_FAILURE → we retry
 *  - Reconciliation reveals a TIMEOUT_AFTER_ISSUE → we update the attempt status
 *
 * One row per attempt. Never update old attempts — append new ones.
 */
class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'attempt_status',
        'provider_reference',
        'scenario',
        'response_payload',
        'error_message',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'attempt_number'   => 'integer',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
