<?php

namespace App\Enums;

/**
 * PolicyStatus defines every valid state a policy request can be in.
 *
 * Lifecycle:
 *   RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED
 *                                    ↘ FAILED
 *                                    ↘ NEEDS_RECONCILIATION
 *
 * Valid transitions are defined in the VALID_TRANSITIONS constant below.
 * Anything not listed there is an invalid/illegal transition.
 */
enum PolicyStatus: string
{
    case RECEIVED               = 'RECEIVED';
    case VALIDATED              = 'VALIDATED';
    case SUBMITTED              = 'SUBMITTED';
    case PENDING_CONFIRMATION   = 'PENDING_CONFIRMATION';
    case ISSUED                 = 'ISSUED';
    case FAILED                 = 'FAILED';
    case NEEDS_RECONCILIATION   = 'NEEDS_RECONCILIATION';

    /**
     * Defines which transitions are allowed.
     * Key = current status, Value = array of statuses we can move to.
     *
     * ISSUED and FAILED are terminal states — nothing can overwrite them.
     * (Except NEEDS_RECONCILIATION can transition to ISSUED after reconciliation.)
     */
    const VALID_TRANSITIONS = [
        'RECEIVED'             => ['VALIDATED', 'FAILED'],
        'VALIDATED'            => ['SUBMITTED', 'FAILED'],
        'SUBMITTED'            => ['PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'NEEDS_RECONCILIATION' => ['SUBMITTED', 'ISSUED', 'FAILED'],
        'ISSUED'               => [], // terminal — nothing can overwrite an issued policy
        'FAILED'               => ['SUBMITTED'], // allow retry after failure
    ];

    /**
     * Check whether transitioning from the current status to $newStatus is valid.
     */
    public function canTransitionTo(PolicyStatus $newStatus): bool
    {
        $allowedTargets = self::VALID_TRANSITIONS[$this->value] ?? [];
        return in_array($newStatus->value, $allowedTargets, strict: true);
    }

    /**
     * Returns true if this is a terminal state (no further transitions allowed).
     */
    public function isTerminal(): bool
    {
        return $this === self::ISSUED; // ISSUED is the only truly terminal success state
    }
}
