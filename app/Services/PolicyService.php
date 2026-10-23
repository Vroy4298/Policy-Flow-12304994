<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PolicyService — orchestrates the main policy lifecycle logic.
 *
 * This is the heart of the application. It coordinates:
 *   - Safe creation of policy requests (idempotency)
 *   - Premium calculation
 *   - Submission to the mock insurer
 *   - State transitions with audit logging
 *   - Reconciliation after timeout
 *
 * All state-changing operations run inside database transactions to ensure
 * atomicity. If anything fails mid-way, the database rolls back to its
 * previous consistent state.
 */
class PolicyService
{
    public function __construct(
        private PremiumCalculator $calculator,
        private MockInsurerService $insurer,
    ) {}

    // ─── Intake ───────────────────────────────────────────────────────────────

    /**
     * Create a new policy request or safely return an existing one.
     *
     * IDEMPOTENCY STRATEGY:
     *   We use a database transaction with a "try INSERT, catch duplicate" pattern.
     *
     *   1. Wrap everything in DB::transaction().
     *   2. Inside the transaction, attempt to INSERT the new row.
     *   3. If the database throws a unique constraint violation on
     *      (agent_id, client_request_id), we catch it and return the existing row.
     *
     *   This is safer than "check-then-insert" (SELECT + INSERT) because:
     *   - Two concurrent requests can both pass a SELECT check.
     *   - Only one INSERT succeeds; the other gets the unique constraint error.
     *   - We never create two logical requests for the same agent+clientId pair.
     *
     * @param  array $data  Validated intake data from the controller
     * @return array{request: PolicyRequest, created: bool}
     */
    public function createOrFind(array $data): array
    {
        // Calculate the premium before starting the transaction
        // (no need to hold a DB lock while doing CPU work)
        $premiumResult = $this->calculator->calculate(
            planCode:    $data['plan_code'],
            dateOfBirth: $data['date_of_birth'],
            tripStart:   $data['trip_start'],
            tripEnd:     $data['trip_end'],
            destination: $data['destination'],
            sumInsured:  (int) $data['sum_insured']
        );

        try {
            // Use a transaction so the INSERT + state history write are atomic
            $policyRequest = DB::transaction(function () use ($data, $premiumResult) {
                $request = PolicyRequest::create([
                    'agent_id'          => $data['agent_id'],
                    'client_request_id' => $data['client_request_id'],
                    'customer_name'     => $data['customer_name'],
                    'date_of_birth'     => $data['date_of_birth'],
                    'destination'       => $data['destination'],
                    'trip_start'        => $data['trip_start'],
                    'trip_end'          => $data['trip_end'],
                    'plan_code'         => $data['plan_code'],
                    'sum_insured'       => (int) $data['sum_insured'],
                    'premium'           => $premiumResult['premium'],
                    'travel_days'       => $premiumResult['travel_days'],
                    'status'            => PolicyStatus::RECEIVED->value,
                    'attempt_count'     => 0,
                ]);

                // Record the initial state in history
                $this->recordStateTransition(
                    policyRequest: $request,
                    fromStatus:    null,
                    toStatus:      PolicyStatus::RECEIVED,
                    reason:        'Policy request received from agent.'
                );

                // Immediately validate (since all validation was done by the controller)
                $this->transitionState(
                    policyRequest: $request,
                    newStatus:     PolicyStatus::VALIDATED,
                    reason:        'All required fields validated successfully. Premium calculated.'
                );

                return $request;
            });

            return ['request' => $policyRequest, 'created' => true];

        } catch (QueryException $e) {
            // Check if this is a unique constraint violation (error code 23000 family)
            if ($this->isDuplicateKeyException($e)) {
                // The (agent_id, client_request_id) pair already exists.
                // Return the existing request — do NOT create a new one.
                $existingRequest = PolicyRequest::where('agent_id', $data['agent_id'])
                    ->where('client_request_id', $data['client_request_id'])
                    ->firstOrFail();

                return ['request' => $existingRequest, 'created' => false];
            }

            // If it's a different database error, re-throw it
            throw $e;
        }
    }

    // ─── Submission ───────────────────────────────────────────────────────────

    /**
     * Submit a policy request to the (mock) insurer.
     *
     * This method handles three scenarios safely:
     *   A. The request submits successfully → transition to ISSUED.
     *   B. The insurer returns a temporary failure → transition to FAILED.
     *   C. The insurer times out (TIMEOUT_AFTER_ISSUE) → reconcile first.
     *
     * CONCURRENCY: We use pessimistic locking (lockForUpdate) to prevent two
     * simultaneous submit requests from issuing the policy twice. Only one
     * request can hold the lock at a time; the second will wait and then see
     * the updated status.
     *
     * @param  PolicyRequest  $policyRequest  The request to submit
     * @param  string|null    $scenarioOverride  Dev-only scenario override
     * @return PolicyRequest  Updated policy request
     *
     * @throws \RuntimeException if the request cannot be submitted in its current state
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenarioOverride = null): PolicyRequest
    {
        return DB::transaction(function () use ($policyRequest, $scenarioOverride) {

            // Re-fetch with a write lock to prevent concurrent re-submission
            // lockForUpdate() means: "lock this row so no other transaction can modify it
            // until this transaction is committed or rolled back."
            $locked = PolicyRequest::lockForUpdate()->findOrFail($policyRequest->id);

            // If it's already ISSUED, return it as-is — never issue twice
            if ($locked->isIssued()) {
                return $locked;
            }

            // Check if the current state allows submission
            if (!$locked->canBeSubmitted()) {
                throw new \RuntimeException(
                    "Cannot submit a policy in '{$locked->status}' status. " .
                    "Only VALIDATED, FAILED, or NEEDS_RECONCILIATION requests can be submitted."
                );
            }

            // Generate a stable provider reference for this submission attempt.
            // If we're retrying, we reuse the existing provider_reference so that
            // reconciliation can match our submission to the insurer's record.
            $providerReference = $locked->provider_reference ?? $this->generateProviderReference($locked);

            // Increment attempt count
            $locked->increment('attempt_count');
            $locked->provider_reference = $providerReference;
            $locked->save();

            // Transition to SUBMITTED
            $this->transitionState(
                policyRequest: $locked,
                newStatus:     PolicyStatus::SUBMITTED,
                reason:        "Submitting to insurer. Attempt #{$locked->attempt_count}."
            );

            // Record this attempt before calling the insurer
            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $locked->id,
                'attempt_number'    => $locked->attempt_count,
                'attempt_status'    => 'PENDING',
                'provider_reference'=> $providerReference,
                'scenario'          => $scenarioOverride ?? config('services.mock_insurer.scenario'),
            ]);

            try {
                // Call the mock insurer
                $response = $this->insurer->issue($providerReference, [], $scenarioOverride);

                // Handle the response
                return $this->handleInsurerResponse($locked, $attempt, $response);

            } catch (\RuntimeException $e) {
                // TIMEOUT_AFTER_ISSUE: The insurer threw an exception simulating a timeout.
                // The insurer MAY have issued the policy. We must reconcile before retrying.
                return $this->handleTimeout($locked, $attempt, $providerReference, $e);
            }
        });
    }

    // ─── Callback Processing ──────────────────────────────────────────────────

    /**
     * Process an inbound callback event from the insurer.
     *
     * IDEMPOTENCY: The provider_event_id has a UNIQUE constraint. Duplicate
     * callbacks are caught by the insert failure and handled gracefully.
     *
     * STATE PROTECTION: If the policy is already ISSUED, we ignore the callback
     * without changing any state. An issued policy stays issued.
     *
     * @param  array $eventData  Validated callback payload
     * @return array{event: \App\Models\ProviderEvent, was_duplicate: bool}
     */
    public function processCallback(array $eventData): array
    {
        return DB::transaction(function () use ($eventData) {

            // Find the policy request by provider_reference
            $policyRequest = PolicyRequest::where('provider_reference', $eventData['provider_reference'])
                ->lockForUpdate()
                ->firstOrFail();

            // Try to insert the event record (UNIQUE on provider_event_id)
            try {
                $event = \App\Models\ProviderEvent::create([
                    'policy_request_id'  => $policyRequest->id,
                    'provider_event_id'  => $eventData['event_id'],
                    'event_type'         => $eventData['event_type'],
                    'policy_number'      => $eventData['policy_number'] ?? null,
                    'provider_reference' => $eventData['provider_reference'],
                    'event_payload'      => $eventData,
                    'was_duplicate'      => false,
                ]);

            } catch (QueryException $e) {
                if ($this->isDuplicateKeyException($e)) {
                    // This exact event_id has been seen before. Return gracefully.
                    $existingEvent = \App\Models\ProviderEvent::where('provider_event_id', $eventData['event_id'])->first();
                    return ['event' => $existingEvent, 'was_duplicate' => true];
                }
                throw $e;
            }

            // Only update policy state if this is a real new event AND the policy is not already ISSUED
            if (!$policyRequest->isIssued() && $eventData['event_type'] === 'ISSUED') {
                $policyRequest->policy_number = $eventData['policy_number'];
                $policyRequest->last_provider_response = $eventData;
                $policyRequest->save();

                $this->transitionState(
                    policyRequest: $policyRequest,
                    newStatus:     PolicyStatus::ISSUED,
                    reason:        'Policy issued via provider callback.',
                    context:       ['event_id' => $eventData['event_id']]
                );
            }

            return ['event' => $event, 'was_duplicate' => false];
        });
    }

    // ─── Reconciliation ───────────────────────────────────────────────────────

    /**
     * Reconcile a policy request that is in NEEDS_RECONCILIATION status.
     *
     * This is called when a TIMEOUT_AFTER_ISSUE has occurred. We:
     *   1. Query the mock insurer's status check API (checkStatus).
     *   2. If the insurer has a record → it issued the policy → update our state to ISSUED.
     *   3. If the insurer has NO record → the policy was NOT issued → we can safely retry.
     *
     * This prevents double-issuance after a timeout.
     *
     * @param  PolicyRequest $policyRequest
     * @return PolicyRequest
     */
    public function reconcile(PolicyRequest $policyRequest): PolicyRequest
    {
        return DB::transaction(function () use ($policyRequest) {
            $locked = PolicyRequest::lockForUpdate()->findOrFail($policyRequest->id);

            if ($locked->isIssued()) {
                return $locked; // already done
            }

            // Ask the mock insurer if they have a record for our provider_reference
            $insurerRecord = $this->insurer->checkStatus($locked->provider_reference);

            if ($insurerRecord && $insurerRecord['policy_number']) {
                // The insurer DID issue the policy. Update our records.
                $locked->policy_number = $insurerRecord['policy_number'];
                $locked->last_provider_response = $insurerRecord;
                $locked->save();

                $this->transitionState(
                    policyRequest: $locked,
                    newStatus:     PolicyStatus::ISSUED,
                    reason:        'Policy recovered via reconciliation. Insurer had already issued it.',
                    context:       ['insurer_record' => $insurerRecord]
                );
            } else {
                // The insurer has NO record. It is safe to retry.
                // Reset to VALIDATED so submit() can run again.
                $this->transitionState(
                    policyRequest: $locked,
                    newStatus:     PolicyStatus::FAILED,
                    reason:        'Reconciliation found no insurer record. Safe to retry.',
                );
            }

            return $locked->fresh();
        });
    }

    // ─── State management helpers ─────────────────────────────────────────────

    /**
     * Transition a policy request to a new status.
     * Validates the transition is allowed, updates the model, and logs history.
     */
    public function transitionState(
        PolicyRequest $policyRequest,
        PolicyStatus  $newStatus,
        string        $reason,
        array         $context = []
    ): void {
        $currentStatus = PolicyStatus::from($policyRequest->status);

        // Reject invalid transitions (e.g., ISSUED → SUBMITTED)
        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw new \RuntimeException(
                "Invalid state transition: {$currentStatus->value} → {$newStatus->value}. " .
                "This transition is not permitted."
            );
        }

        $fromStatus = $policyRequest->status;
        $policyRequest->status = $newStatus->value;
        $policyRequest->save();

        // Always log the transition
        $this->recordStateTransition($policyRequest, PolicyStatus::from($fromStatus), $newStatus, $reason, $context);
    }

    /**
     * Record a state transition in the history table.
     * $fromStatus is null only for the very first RECEIVED state.
     */
    private function recordStateTransition(
        PolicyRequest  $policyRequest,
        ?PolicyStatus  $fromStatus,
        PolicyStatus   $toStatus,
        string         $reason,
        array          $context = []
    ): void {
        PolicyStateHistory::create([
            'policy_request_id' => $policyRequest->id,
            'from_status'       => $fromStatus?->value,
            'to_status'         => $toStatus->value,
            'reason'            => $reason,
            'context'           => empty($context) ? null : $context,
        ]);
    }

    // ─── Private response handlers ────────────────────────────────────────────

    /**
     * Process a successful or failed response from the mock insurer.
     */
    private function handleInsurerResponse(
        PolicyRequest   $policyRequest,
        IssuanceAttempt $attempt,
        array           $response
    ): PolicyRequest {
        if ($response['status'] === 'ISSUED') {
            // Update the attempt record
            $attempt->update([
                'attempt_status'  => 'SUCCESS',
                'response_payload' => $response,
            ]);

            // Update the policy request
            $policyRequest->policy_number = $response['policy_number'];
            $policyRequest->last_provider_response = $response;
            $policyRequest->save();

            $this->transitionState(
                policyRequest: $policyRequest,
                newStatus:     PolicyStatus::ISSUED,
                reason:        'Policy issued by insurer synchronously.',
                context:       ['policy_number' => $response['policy_number']]
            );

        } elseif ($response['status'] === 'PENDING') {
            // DELAYED_CALLBACK scenario: waiting for callback
            $attempt->update([
                'attempt_status'  => 'PENDING',
                'response_payload' => $response,
            ]);

            $policyRequest->last_provider_response = $response;
            $policyRequest->save();

            $this->transitionState(
                policyRequest: $policyRequest,
                newStatus:     PolicyStatus::PENDING_CONFIRMATION,
                reason:        'Insurer returned pending status. Awaiting callback.'
            );

        } else {
            // TEMPORARY_FAILURE scenario
            $attempt->update([
                'attempt_status'  => 'FAILED',
                'response_payload' => $response,
                'error_message'    => $response['message'] ?? 'Unknown failure',
            ]);

            $policyRequest->last_provider_response = $response;
            $policyRequest->save();

            $this->transitionState(
                policyRequest: $policyRequest,
                newStatus:     PolicyStatus::FAILED,
                reason:        'Insurer returned a failure response. Can be retried.',
                context:       ['error' => $response['message'] ?? 'unknown']
            );
        }

        return $policyRequest->fresh();
    }

    /**
     * Handle the TIMEOUT_AFTER_ISSUE scenario.
     * The insurer may have issued the policy but we didn't receive the response.
     * We set status to NEEDS_RECONCILIATION so reconcile() can be called.
     */
    private function handleTimeout(
        PolicyRequest   $policyRequest,
        IssuanceAttempt $attempt,
        string          $providerReference,
        \RuntimeException $exception
    ): PolicyRequest {
        $attempt->update([
            'attempt_status' => 'TIMEOUT',
            'error_message'  => $exception->getMessage(),
        ]);

        $policyRequest->last_provider_response = ['error' => 'Timeout — reconciliation required.'];
        $policyRequest->save();

        $this->transitionState(
            policyRequest: $policyRequest,
            newStatus:     PolicyStatus::NEEDS_RECONCILIATION,
            reason:        'Insurer call timed out. Cannot determine if policy was issued. Reconciliation needed.',
            context:       ['provider_reference' => $providerReference]
        );

        return $policyRequest->fresh();
    }

    // ─── Utility helpers ─────────────────────────────────────────────────────

    /**
     * Generate a stable, unique provider reference for this policy request.
     * Format: PR-<policy_request_id>-<8 hex chars>
     *
     * Using the policy_request_id as a prefix makes reconciliation easier:
     * we can always trace a provider_reference back to our internal record.
     */
    private function generateProviderReference(PolicyRequest $policyRequest): string
    {
        $randomPart = strtoupper(substr(Str::uuid()->getHex()->toString(), 0, 8));
        return "PR-{$policyRequest->id}-{$randomPart}";
    }

    /**
     * Check whether a QueryException is caused by a unique key constraint violation.
     * MySQL/MariaDB error code 1062 = Duplicate entry for key.
     */
    private function isDuplicateKeyException(QueryException $e): bool
    {
        $errorCode = $e->errorInfo[1] ?? null;
        return $errorCode === 1062;
    }
}
