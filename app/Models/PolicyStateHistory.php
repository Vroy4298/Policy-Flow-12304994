<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyStateHistory — immutable audit trail for all status transitions.
 *
 * Every status change on a PolicyRequest appends one row here.
 * We never update or delete these rows. They are a permanent record.
 */
class PolicyStateHistory extends Model
{
    // Use 'transitioned_at' as the timestamp; disable updated_at
    const UPDATED_AT = null;
    const CREATED_AT = 'transitioned_at';

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'reason',
        'context',
    ];

    protected $casts = [
        'context'         => 'array',
        'transitioned_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
