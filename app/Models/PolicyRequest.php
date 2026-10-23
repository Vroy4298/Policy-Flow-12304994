<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PolicyRequest — the central entity.
 *
 * Represents a single logical request from an agent to issue a travel
 * insurance policy. One logical request = one row in this table.
 *
 * The unique(agent_id, client_request_id) database constraint ensures that
 * repeated requests from the same agent with the same client_request_id
 * are detected and handled safely, even under concurrent load.
 */
class PolicyRequest extends Model
{
    protected $fillable = [
        'agent_id',
        'client_request_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'travel_days',
        'status',
        'provider_reference',
        'policy_number',
        'attempt_count',
        'last_provider_response',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'trip_start'             => 'date',
        'trip_end'               => 'date',
        'premium'                => 'decimal:2',
        'last_provider_response' => 'array',
        'attempt_count'          => 'integer',
        'travel_days'            => 'integer',
        'sum_insured'            => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Get the current status as a PolicyStatus enum.
     */
    public function getStatusEnum(): PolicyStatus
    {
        return PolicyStatus::from($this->status);
    }

    /**
     * Returns true if this request is already in a terminal issued state.
     * Used to guard against re-issuance.
     */
    public function isIssued(): bool
    {
        return $this->status === PolicyStatus::ISSUED->value;
    }

    /**
     * Returns true if this request can still be submitted to the insurer.
     * VALIDATED, FAILED (retry), and NEEDS_RECONCILIATION are submittable.
     */
    public function canBeSubmitted(): bool
    {
        return in_array($this->status, [
            PolicyStatus::VALIDATED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
        ], strict: true);
    }
}
