<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Services\MockInsurerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PolicyRequestTest — feature tests for the PolicyFlow API.
 *
 * Each test method covers one specific behaviour requirement from the assessment.
 * Tests use RefreshDatabase to ensure a clean database for each test.
 * The policyflow_test database is used (configured in phpunit.xml).
 *
 * Run with: php artisan test --filter PolicyRequestTest
 */
class PolicyRequestTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helper: valid base payload ───────────────────────────────────────────

    /**
     * Returns a valid intake payload.
     * We can override specific fields for each test using array_merge().
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id'          => 'AGENT-17',
            'customer_name'     => 'Sample Customer',
            'date_of_birth'     => '1998-04-10',  // age 28 on 2026-11-10
            'destination'       => 'INTERNATIONAL',
            'trip_start'        => '2026-11-10',
            'trip_end'          => '2026-11-14',
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
        ], $overrides);
    }

    // ─── Test 1: Premium calculation ──────────────────────────────────────────

    /**
     * The reference example from the assessment specification.
     * PLUS × 5 days × age 28 (1.00) × INTERNATIONAL (1.80) × 300000 (1.25) = ₹225.00
     */
    public function test_correct_premium_is_calculated_for_reference_example(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '225.00');
        $response->assertJsonPath('data.travel_days', 5);
        $response->assertJsonPath('data.status', 'VALIDATED');
    }

    /**
     * BASIC plan, 3 days, DOMESTIC, sum 100000, age 30
     * = 12 × 3 × 1.00 × 1.00 × 1.00 = ₹36.00
     */
    public function test_premium_calculation_basic_plan_domestic(): void
    {
        $payload = $this->validPayload([
            'plan_code'   => 'BASIC',
            'destination' => 'DOMESTIC',
            'sum_insured' => 100000,
            'trip_start'  => '2026-11-10',
            'trip_end'    => '2026-11-12',  // 3 days
            'date_of_birth' => '1996-01-01', // age ~30
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '36.00');
    }

    /**
     * PREMIUM plan, 1 day, INTERNATIONAL, sum 500000, age 65 (1.50 multiplier)
     * = 30 × 1 × 1.50 × 1.80 × 1.50 = ₹121.50
     */
    public function test_premium_calculation_premium_plan_age_65(): void
    {
        $payload = $this->validPayload([
            'plan_code'     => 'PREMIUM',
            'destination'   => 'INTERNATIONAL',
            'sum_insured'   => 500000,
            'trip_start'    => '2026-11-10',
            'trip_end'      => '2026-11-10',  // 1 day
            'date_of_birth' => '1961-06-01',  // age 65 on trip_start
            'client_request_id' => 'AGT-TEST-002',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '121.50');
    }

    /**
     * Age bracket 0–17: multiplier 1.10
     * BASIC, 2 days, DOMESTIC, 100000, age 15
     * = 12 × 2 × 1.10 × 1.00 × 1.00 = ₹26.40
     */
    public function test_premium_calculation_child_age_bracket(): void
    {
        $payload = $this->validPayload([
            'plan_code'     => 'BASIC',
            'destination'   => 'DOMESTIC',
            'sum_insured'   => 100000,
            'trip_start'    => '2026-11-10',
            'trip_end'      => '2026-11-11',
            'date_of_birth' => '2011-03-01', // age 15
            'client_request_id' => 'AGT-TEST-003',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '26.40');
    }

    /**
     * Age bracket 71+: multiplier 2.00
     * PLUS, 2 days, DOMESTIC, 100000, age 75
     * = 20 × 2 × 2.00 × 1.00 × 1.00 = ₹80.00
     */
    public function test_premium_calculation_senior_age_bracket_71_plus(): void
    {
        $payload = $this->validPayload([
            'plan_code'     => 'PLUS',
            'destination'   => 'DOMESTIC',
            'sum_insured'   => 100000,
            'trip_start'    => '2026-11-10',
            'trip_end'      => '2026-11-11',
            'date_of_birth' => '1950-01-01', // age 76
            'client_request_id' => 'AGT-TEST-004',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '80.00');
    }

    // ─── Test 2: Validation errors ────────────────────────────────────────────

    /**
     * trip_end before trip_start should return 422 with a meaningful error.
     */
    public function test_invalid_travel_dates_are_rejected(): void
    {
        $payload = $this->validPayload([
            'trip_start' => '2026-11-14',
            'trip_end'   => '2026-11-10', // BEFORE trip_start
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('trip_end');
    }

    /**
     * Unsupported plan_code should return 422.
     */
    public function test_unsupported_plan_code_is_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'GOLD', // not in BASIC, PLUS, PREMIUM
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('plan_code');
    }

    /**
     * Unsupported sum_insured value should return 422.
     */
    public function test_unsupported_sum_insured_is_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'sum_insured' => 200000, // not in 100000, 300000, 500000
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sum_insured');
    }

    /**
     * Missing required fields should return 422.
     */
    public function test_missing_required_fields_return_422(): void
    {
        $response = $this->postJson('/api/policy-requests', [
            'agent_id' => 'AGENT-17',
            // missing: client_request_id, customer_name, etc.
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_request_id', 'customer_name', 'date_of_birth']);
    }

    /**
     * Unsupported destination should return 422.
     */
    public function test_unsupported_destination_is_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'destination' => 'REGIONAL', // not DOMESTIC or INTERNATIONAL
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('destination');
    }

    // ─── Test 3: New request creation ────────────────────────────────────────

    /**
     * A new, valid request should return HTTP 201 and include the calculated premium.
     */
    public function test_new_request_returns_201_with_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJson(['created' => true]);
        $response->assertJsonStructure([
            'data' => ['id', 'premium', 'status', 'travel_days'],
        ]);

        // Verify it was saved to the database
        $this->assertDatabaseHas('policy_requests', [
            'agent_id'          => 'AGENT-17',
            'client_request_id' => 'AGT-2026-0001',
            'status'            => 'VALIDATED',
        ]);
    }

    // ─── Test 4: Duplicate intake idempotency ─────────────────────────────────

    /**
     * Reliability Case A: Sending the same request twice should return the
     * same logical request with HTTP 200 on the second call — not create a new one.
     */
    public function test_duplicate_intake_returns_200_and_same_request(): void
    {
        // First request: creates a new policy request
        $first = $this->postJson('/api/policy-requests', $this->validPayload());
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        // Second request: same agent_id + client_request_id → must return existing
        $second = $this->postJson('/api/policy-requests', $this->validPayload());
        $second->assertStatus(200);
        $second->assertJson(['created' => false]);
        $secondId = $second->json('data.id');

        // The IDs must be identical — no duplicate was created
        $this->assertEquals($firstId, $secondId);

        // Only one row should exist in the database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Even with different field values but the same agent_id + client_request_id,
     * the existing request is returned. The duplicate prevention is based on
     * the key pair, not the payload content.
     */
    public function test_duplicate_intake_is_safe_even_with_different_payload(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertStatus(201);

        // Second request with a different customer_name but same key pair
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'customer_name' => 'Different Name Entirely',
        ]));

        $response->assertStatus(200);
        $response->assertJson(['created' => false]);

        // Still only one row
        $this->assertDatabaseCount('policy_requests', 1);
    }

    // ─── Test 5: Successful issuance ─────────────────────────────────────────

    /**
     * A successful submission should produce exactly one policy_number.
     * The mock insurer scenario is SUCCESS (default).
     */
    public function test_successful_issuance_produces_exactly_one_policy_number(): void
    {
        // Create the request
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $createResponse->assertStatus(201);
        $requestId = $createResponse->json('data.id');

        // Submit it with SUCCESS scenario
        $submitResponse = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_SUCCESS,
        ]);

        $submitResponse->assertStatus(200);
        $submitResponse->assertJsonPath('data.status', 'ISSUED');

        // The policy_number must be set and non-null
        $policyNumber = $submitResponse->json('data.policy_number');
        $this->assertNotNull($policyNumber, 'policy_number must be set after issuance');
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify in database
        $this->assertDatabaseHas('policy_requests', [
            'id'     => $requestId,
            'status' => 'ISSUED',
        ]);

        // The policy_number column must be UNIQUE — verify only one exists
        $this->assertDatabaseCount('policy_requests', 1);
        $count = PolicyRequest::where('policy_number', $policyNumber)->count();
        $this->assertEquals(1, $count, 'Exactly one request should have this policy number');
    }

    /**
     * Submitting the same request twice should NOT produce two policy issuances.
     * The second submit should return the existing ISSUED state.
     */
    public function test_submitting_an_issued_request_does_not_issue_again(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        // First submit → ISSUED
        $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_SUCCESS,
        ])->assertStatus(200);

        $firstPolicyNumber = PolicyRequest::find($requestId)->policy_number;

        // Second submit → must still be ISSUED, same policy number
        $secondSubmit = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_SUCCESS,
        ]);

        $secondSubmit->assertStatus(200);
        $secondSubmit->assertJsonPath('data.status', 'ISSUED');

        $secondPolicyNumber = $secondSubmit->json('data.policy_number');
        $this->assertEquals($firstPolicyNumber, $secondPolicyNumber, 'Policy number must not change after re-submit');

        // Only one issuance attempt should have been recorded
        // (second submit sees ISSUED status and returns early)
        $attempts = \App\Models\IssuanceAttempt::where('policy_request_id', $requestId)->count();
        $this->assertEquals(1, $attempts, 'Should only have one issuance attempt');
    }

    // ─── Test 6: Timeout after success (Reliability Case B) ──────────────────

    /**
     * Reliability Case B: If the insurer creates a policy but the response times out,
     * reconciliation must discover the policy number WITHOUT issuing a new one.
     *
     * Steps:
     *   1. Create a policy request.
     *   2. Submit with TIMEOUT_AFTER_ISSUE scenario → status becomes NEEDS_RECONCILIATION.
     *   3. Call reconcile → discovers the policy from the mock insurer's DB.
     *   4. Status becomes ISSUED with the correct policy number.
     *   5. No second issuance occurs.
     */
    public function test_timeout_after_issue_recovers_via_reconciliation(): void
    {
        // Step 1: Create the policy request
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        // Step 2: Submit with TIMEOUT scenario → the insurer creates a policy
        // but throws a timeout exception, so we get NEEDS_RECONCILIATION
        $submitResponse = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_TIMEOUT_AFTER_ISSUE,
        ]);

        $submitResponse->assertStatus(200);
        $submitResponse->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        $providerReference = $submitResponse->json('data.provider_reference');
        $this->assertNotNull($providerReference);

        // Step 3: Verify the mock insurer HAS a record (it issued the policy)
        $mockRecord = \App\Models\MockInsurerPolicy::where('provider_reference', $providerReference)->first();
        $this->assertNotNull($mockRecord, 'Mock insurer should have a record for this provider reference');
        $this->assertFalse($mockRecord->response_delivered, 'Response should be marked as not delivered');

        // Step 4: Reconcile — this should discover the policy and set ISSUED
        $policyRequest = PolicyRequest::find($requestId);
        $policyService = app(\App\Services\PolicyService::class);
        $reconciled = $policyService->reconcile($policyRequest);

        $this->assertEquals('ISSUED', $reconciled->status);
        $this->assertNotNull($reconciled->policy_number);
        $this->assertEquals($mockRecord->policy_number, $reconciled->policy_number);

        // Step 5: Verify no second policy was created in the mock insurer
        $mockPolicyCount = \App\Models\MockInsurerPolicy::where('provider_reference', $providerReference)->count();
        $this->assertEquals(1, $mockPolicyCount, 'Only one mock policy should exist for this reference');
    }

    // ─── Test 7: Duplicate callback processing ───────────────────────────────

    /**
     * Sending the same callback event twice should be handled idempotently.
     * The second callback must NOT change the policy state or produce errors.
     */
    public function test_duplicate_callback_is_handled_idempotently(): void
    {
        // Create and submit a request to get to PENDING_CONFIRMATION state
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        $submitResponse = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_DELAYED_CALLBACK,
        ]);
        $submitResponse->assertJsonPath('data.status', 'PENDING_CONFIRMATION');

        $providerRef = PolicyRequest::find($requestId)->provider_reference;

        $callbackPayload = [
            'event_id'           => 'EVT-UNIQUE-001',
            'event_type'         => 'ISSUED',
            'provider_reference' => $providerRef,
            'policy_number'      => 'POL-20261123-ABCD1234',
        ];

        // First callback: should set ISSUED
        $first = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $first->assertStatus(200);
        $first->assertJsonPath('was_duplicate', false);

        $this->assertEquals('ISSUED', PolicyRequest::find($requestId)->status);

        // Second callback: same event_id → must be identified as duplicate
        $second = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $second->assertStatus(200);
        $second->assertJsonPath('was_duplicate', true);

        // Policy state must still be ISSUED — not changed
        $this->assertEquals('ISSUED', PolicyRequest::find($requestId)->status);

        // Still the same policy number
        $this->assertEquals(
            'POL-20261123-ABCD1234',
            PolicyRequest::find($requestId)->policy_number
        );
    }

    // ─── Test 8: Invalid state transitions ───────────────────────────────────

    /**
     * An ISSUED policy must not be overwritten by a subsequent callback.
     * This tests that ISSUED is a protected terminal state.
     */
    public function test_issued_policy_is_protected_from_overwrite(): void
    {
        // Create, submit, and issue a policy
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_SUCCESS,
        ])->assertStatus(200);

        $originalPolicyNumber = PolicyRequest::find($requestId)->policy_number;

        // Now try to send a "FAILED" callback — should be ignored because status is ISSUED
        $providerRef = PolicyRequest::find($requestId)->provider_reference;
        $failedCallbackPayload = [
            'event_id'           => 'EVT-FAIL-001',
            'event_type'         => 'FAILED',
            'provider_reference' => $providerRef,
            'policy_number'      => null,
        ];

        $response = $this->postJson('/api/mock-insurer/callback', $failedCallbackPayload);
        $response->assertStatus(200);

        // Status must still be ISSUED
        $final = PolicyRequest::find($requestId);
        $this->assertEquals('ISSUED', $final->status);
        $this->assertEquals($originalPolicyNumber, $final->policy_number);
    }

    // ─── Test 9: Listing and pagination ──────────────────────────────────────

    /**
     * GET /api/policy-requests should return paginated results.
     */
    public function test_listing_returns_paginated_results(): void
    {
        // Create 3 policy requests with different client_request_ids
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/policy-requests', $this->validPayload([
                'client_request_id' => "AGT-LIST-{$i}",
            ]));
        }

        $response = $this->getJson('/api/policy-requests');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $this->assertEquals(3, $response->json('meta.total'));
    }

    /**
     * Filtering by status should return only matching requests.
     */
    public function test_listing_filters_by_status(): void
    {
        // Create 2 VALIDATED requests
        $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'L1']));
        $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'L2']));

        // Create 1 ISSUED request
        $resp = $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'L3']));
        $id = $resp->json('data.id');
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        // Filter for VALIDATED only
        $validatedResponse = $this->getJson('/api/policy-requests?status=VALIDATED');
        $validatedResponse->assertStatus(200);
        $this->assertEquals(2, $validatedResponse->json('meta.total'));

        // Filter for ISSUED only
        $issuedResponse = $this->getJson('/api/policy-requests?status=ISSUED');
        $issuedResponse->assertStatus(200);
        $this->assertEquals(1, $issuedResponse->json('meta.total'));
    }

    // ─── Test 10: Detail view ─────────────────────────────────────────────────

    /**
     * GET /api/policy-requests/{id} should return the policy with state history.
     */
    public function test_detail_view_includes_state_history(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$requestId}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id', 'status', 'premium',
                'state_history' => [['from', 'to', 'reason', 'at']],
                'issuance_attempts',
            ],
        ]);

        // Should have history entries: RECEIVED and VALIDATED transitions
        $history = $response->json('data.state_history');
        $this->assertCount(2, $history); // RECEIVED → VALIDATED
    }

    /**
     * Requesting a non-existent ID returns 404.
     */
    public function test_show_nonexistent_id_returns_404(): void
    {
        $response = $this->getJson('/api/policy-requests/99999');
        $response->assertStatus(404);
    }

    // ─── Test 11: Temporary failure and retry ─────────────────────────────────

    /**
     * A temporary failure should transition to FAILED but remain retryable.
     * Retrying should create a new issuance attempt.
     */
    public function test_temporary_failure_can_be_retried(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $requestId = $createResponse->json('data.id');

        // First attempt: TEMPORARY_FAILURE
        $failResponse = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_TEMPORARY_FAILURE,
        ]);
        $failResponse->assertStatus(200);
        $failResponse->assertJsonPath('data.status', 'FAILED');

        // Verify we recorded one attempt
        $this->assertEquals(
            1,
            \App\Models\IssuanceAttempt::where('policy_request_id', $requestId)->count()
        );

        // Second attempt: SUCCESS
        $retryResponse = $this->postJson("/api/policy-requests/{$requestId}/submit", [
            'scenario' => MockInsurerService::SCENARIO_SUCCESS,
        ]);
        $retryResponse->assertStatus(200);
        $retryResponse->assertJsonPath('data.status', 'ISSUED');

        // Now there should be 2 attempts
        $this->assertEquals(
            2,
            \App\Models\IssuanceAttempt::where('policy_request_id', $requestId)->count()
        );
    }
}
