<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MockInsurerController — handles the inbound provider callback endpoint.
 *
 * Endpoint:
 *   POST /api/mock-insurer/callback
 *
 * In a real system, this endpoint would receive callbacks from an actual
 * external insurer. Here, it is used by our own mock insurer and by tests.
 *
 * IDEMPOTENCY: Duplicate callbacks (same event_id) are handled safely
 * without reprocessing. The database's UNIQUE constraint on provider_event_id
 * is the final safety net.
 *
 * NOTE: In production, this endpoint should verify a shared secret or
 * signature to prevent unauthorised callers from forging callbacks.
 * For this assessment, authentication is out of scope.
 */
class MockInsurerController extends Controller
{
    public function __construct(
        private PolicyService $policyService,
    ) {}

    /**
     * Receive and process an inbound insurer callback event.
     *
     * Expected payload:
     * {
     *   "event_id":          "EVT-unique-id",
     *   "event_type":        "ISSUED" | "FAILED",
     *   "provider_reference": "PR-...",
     *   "policy_number":     "POL-...",    (required if ISSUED)
     *   "message":           "..."         (optional)
     * }
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id'           => ['required', 'string', 'max:100'],
            'event_type'         => ['required', 'string', 'in:ISSUED,FAILED,STATUS_UPDATE'],
            'provider_reference' => ['required', 'string'],
            'policy_number'      => ['nullable', 'string'],
            'message'            => ['nullable', 'string'],
        ]);

        $result = $this->policyService->processCallback($validated);

        if ($result['was_duplicate']) {
            return response()->json([
                'message'      => 'Duplicate event received and safely ignored.',
                'was_duplicate'=> true,
                'event_id'     => $validated['event_id'],
            ], 200);
        }

        return response()->json([
            'message'      => 'Callback processed successfully.',
            'was_duplicate'=> false,
            'event_id'     => $validated['event_id'],
        ], 200);
    }
}
