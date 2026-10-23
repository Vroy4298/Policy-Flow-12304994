<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderEvent — records every inbound callback from the insurer.
 *
 * The provider_event_id column has a UNIQUE constraint.
 * When a duplicate callback arrives, the INSERT fails and we return 200 OK
 * without reprocessing. This is the "insert-and-catch" idempotency pattern.
 */
class ProviderEvent extends Model
{
    protected $fillable = [
        'policy_request_id',
        'provider_event_id',
        'event_type',
        'policy_number',
        'provider_reference',
        'event_payload',
        'was_duplicate',
    ];

    protected $casts = [
        'event_payload' => 'array',
        'was_duplicate' => 'boolean',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
