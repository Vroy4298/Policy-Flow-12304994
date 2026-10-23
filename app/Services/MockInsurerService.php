<?php

namespace App\Services;

use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

/**
 * MockInsurerService — simulates an external travel insurance provider API.
 *
 * This service is DEVELOPMENT-ONLY. It is not a real insurer.
 *
 * It supports five deterministic scenarios, controlled by:
 *   1. The MOCK_INSURER_SCENARIO environment variable (global default).
 *   2. The $scenarioOverride parameter on issue() (per-request override,
 *      only accepted from trusted internal callers — the submit endpoint
 *      reads it from the request body only in APP_ENV=local|testing).
 *
 * Scenarios:
 *   SUCCESS              — Issues a policy and returns the policy number.
 *   TEMPORARY_FAILURE    — Returns a retriable error (no policy created).
 *   TIMEOUT_AFTER_ISSUE  — Creates a policy in mock DB but throws a timeout exception.
 *   DELAYED_CALLBACK     — Returns "pending", callback must be sent separately.
 *   DUPLICATE_CALLBACK   — Same as SUCCESS but triggers a second identical callback.
 *
 * The mock_insurer_policies table acts as the insurer's own database.
 * The checkStatus() method is the equivalent of a "status lookup" API.
 */
class MockInsurerService
{
    // Supported scenario names
    const SCENARIO_SUCCESS             = 'SUCCESS';
    const SCENARIO_TEMPORARY_FAILURE   = 'TEMPORARY_FAILURE';
    const SCENARIO_TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    const SCENARIO_DELAYED_CALLBACK    = 'DELAYED_CALLBACK';
    const SCENARIO_DUPLICATE_CALLBACK  = 'DUPLICATE_CALLBACK';

    const ALL_SCENARIOS = [
        self::SCENARIO_SUCCESS,
        self::SCENARIO_TEMPORARY_FAILURE,
        self::SCENARIO_TIMEOUT_AFTER_ISSUE,
        self::SCENARIO_DELAYED_CALLBACK,
        self::SCENARIO_DUPLICATE_CALLBACK,
    ];

    /**
     * Attempt to issue a policy at the (mock) insurer.
     *
     * @param  string  $providerReference  A unique reference we generate for this submission.
     * @param  array   $policyData         Customer and trip details.
     * @param  string|null $scenarioOverride  Override the default scenario (dev/test only).
     * @return array   Result with keys: status, policy_number, provider_reference, message
     *
     * @throws \RuntimeException when the scenario is TIMEOUT_AFTER_ISSUE
     */
    public function issue(
        string $providerReference,
        array  $policyData,
        ?string $scenarioOverride = null
    ): array {
        $scenario = $this->resolveScenario($scenarioOverride);

        return match ($scenario) {
            self::SCENARIO_SUCCESS           => $this->handleSuccess($providerReference, $scenario),
            self::SCENARIO_TEMPORARY_FAILURE => $this->handleTemporaryFailure($providerReference),
            self::SCENARIO_TIMEOUT_AFTER_ISSUE => $this->handleTimeoutAfterIssue($providerReference, $scenario),
            self::SCENARIO_DELAYED_CALLBACK  => $this->handleDelayedCallback($providerReference),
            self::SCENARIO_DUPLICATE_CALLBACK => $this->handleDuplicateCallback($providerReference, $scenario),
            default => throw new \InvalidArgumentException("Unknown mock scenario: $scenario"),
        };
    }

    /**
     * Check whether the insurer has a record for a given provider_reference.
     *
     * This simulates a "status lookup" or "reconciliation check" API that a
     * real insurer would provide. We call this when a TIMEOUT_AFTER_ISSUE
     * occurs so that we can recover the policy number without issuing again.
     *
     * @param  string $providerReference  The reference we used when submitting.
     * @return array|null  Policy record if found, null if not found.
     */
    public function checkStatus(string $providerReference): ?array
    {
        $mockPolicy = MockInsurerPolicy::where('provider_reference', $providerReference)->first();

        if (!$mockPolicy) {
            return null;
        }

        return [
            'provider_reference' => $mockPolicy->provider_reference,
            'policy_number'      => $mockPolicy->policy_number,
            'scenario'           => $mockPolicy->scenario,
            'response_delivered' => $mockPolicy->response_delivered,
        ];
    }

    // ─── Private scenario handlers ────────────────────────────────────────────

    /**
     * SUCCESS: Creates a mock policy record and returns the policy number.
     */
    private function handleSuccess(string $providerReference, string $scenario): array
    {
        $policyNumber = $this->generatePolicyNumber();

        // Record in the mock insurer's "database"
        MockInsurerPolicy::create([
            'provider_reference' => $providerReference,
            'policy_number'      => $policyNumber,
            'scenario'           => $scenario,
            'response_delivered' => true,
        ]);

        return [
            'status'             => 'ISSUED',
            'policy_number'      => $policyNumber,
            'provider_reference' => $providerReference,
            'message'            => 'Policy issued successfully.',
        ];
    }

    /**
     * TEMPORARY_FAILURE: Returns a retriable error. No policy is created.
     * The caller should retry later.
     */
    private function handleTemporaryFailure(string $providerReference): array
    {
        return [
            'status'             => 'FAILED',
            'policy_number'      => null,
            'provider_reference' => $providerReference,
            'message'            => 'Temporary service unavailable. Please retry.',
            'retriable'          => true,
        ];
    }

    /**
     * TIMEOUT_AFTER_ISSUE: Creates a policy in the mock DB (policy is really issued!)
     * but then throws a RuntimeException to simulate the network timing out before
     * the response reaches the caller.
     *
     * Reconciliation should later call checkStatus() to discover the policy.
     */
    private function handleTimeoutAfterIssue(string $providerReference, string $scenario): array
    {
        $policyNumber = $this->generatePolicyNumber();

        // The insurer DID create the policy — this row proves it
        MockInsurerPolicy::create([
            'provider_reference' => $providerReference,
            'policy_number'      => $policyNumber,
            'scenario'           => $scenario,
            'response_delivered' => false, // signals: response was NOT delivered to caller
        ]);

        // Now simulate the timeout — the caller will not receive the policy number
        throw new \RuntimeException(
            'Mock insurer timeout: The insurer issued the policy but the connection was lost. ' .
            'Use checkStatus() to reconcile. Provider reference: ' . $providerReference
        );
    }

    /**
     * DELAYED_CALLBACK: Returns a "pending" status. The insurer will send a
     * callback separately to POST /api/mock-insurer/callback.
     *
     * In this implementation the callback must be triggered manually or via a test.
     */
    private function handleDelayedCallback(string $providerReference): array
    {
        return [
            'status'             => 'PENDING',
            'policy_number'      => null,
            'provider_reference' => $providerReference,
            'message'            => 'Policy is being processed. Confirmation will be sent via callback.',
        ];
    }

    /**
     * DUPLICATE_CALLBACK: Issues a policy (like SUCCESS) but also returns a flag
     * telling the application to expect a duplicate callback. The test suite will
     * then call the callback endpoint twice.
     */
    private function handleDuplicateCallback(string $providerReference, string $scenario): array
    {
        $result = $this->handleSuccess($providerReference, $scenario);
        $result['expect_duplicate_callback'] = true;
        return $result;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Determine which scenario to run.
     * Uses the override if provided; falls back to the environment variable.
     */
    private function resolveScenario(?string $override): string
    {
        if ($override && in_array($override, self::ALL_SCENARIOS, strict: true)) {
            return $override;
        }

        return config('services.mock_insurer.scenario', self::SCENARIO_SUCCESS);
    }

    /**
     * Generate a unique, human-readable policy number.
     * Format: POL-<YYYYMMDD>-<8 uppercase hex chars>
     */
    private function generatePolicyNumber(): string
    {
        $date       = now()->format('Ymd');
        $randomPart = strtoupper(substr(Str::uuid()->getHex()->toString(), 0, 8));
        return "POL-{$date}-{$randomPart}";
    }
}
