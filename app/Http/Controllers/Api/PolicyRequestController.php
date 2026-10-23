<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use App\Services\PremiumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\PolicyRequest;

/**
 * PolicyRequestController — handles the main policy request lifecycle API.
 *
 * Endpoints:
 *   POST   /api/policy-requests            → createOrFind()
 *   GET    /api/policy-requests            → index()
 *   GET    /api/policy-requests/{id}       → show()
 *   POST   /api/policy-requests/{id}/submit → submit()
 */
class PolicyRequestController extends Controller
{
    public function __construct(
        private PolicyService $policyService,
    ) {}

    // ─── POST /api/policy-requests ────────────────────────────────────────────

    /**
     * Create a new policy request, or return the existing one for this
     * agent_id + client_request_id combination (idempotent).
     *
     * HTTP 201 → new request created
     * HTTP 200 → existing request returned (idempotent repeat)
     * HTTP 422 → validation error
     */
    public function createOrFind(Request $request): JsonResponse
    {
        // Validate all required fields
        $validated = $request->validate([
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id'          => ['required', 'string', 'max:100'],
            'customer_name'     => ['required', 'string', 'max:255'],
            'date_of_birth'     => ['required', 'date_format:Y-m-d', 'before:today'],
            'destination'       => ['required', Rule::in(PremiumCalculator::SUPPORTED_DESTINATIONS)],
            'trip_start'        => ['required', 'date_format:Y-m-d'],
            'trip_end'          => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code'         => ['required', Rule::in(PremiumCalculator::SUPPORTED_PLANS)],
            'sum_insured'       => ['required', 'integer', Rule::in(PremiumCalculator::SUPPORTED_SUM_INSURED)],
        ]);

        $result = $this->policyService->createOrFind($validated);

        $statusCode = $result['created'] ? 201 : 200;

        return response()->json([
            'data'    => $this->formatPolicyRequest($result['request']),
            'created' => $result['created'],
            'message' => $result['created']
                ? 'Policy request created successfully.'
                : 'Existing policy request returned (duplicate client_request_id).',
        ], $statusCode);
    }

    // ─── GET /api/policy-requests ─────────────────────────────────────────────

    /**
     * List all policy requests with optional status filter and pagination.
     *
     * Query params:
     *   ?status=VALIDATED      → filter by status
     *   ?per_page=15           → items per page (default 15, max 100)
     *   ?page=2                → page number
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query()->orderByDesc('created_at');

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        // Pagination (default 15, max 100)
        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($r) => $this->formatPolicyRequest($r))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    // ─── GET /api/policy-requests/{id} ───────────────────────────────────────

    /**
     * Retrieve a specific policy request by ID, including its full state history.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['stateHistory', 'issuanceAttempts'])->findOrFail($id);

        return response()->json([
            'data' => $this->formatPolicyRequestDetailed($policyRequest),
        ]);
    }

    // ─── POST /api/policy-requests/{id}/submit ───────────────────────────────

    /**
     * Submit a validated policy request to the insurer.
     *
     * Accepts an optional 'scenario' field for development/testing.
     * This field is only accepted when APP_ENV is local or testing.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::findOrFail($id);

        // Only allow scenario override in non-production environments
        $scenarioOverride = null;
        if (app()->environment(['local', 'testing'])) {
            $scenarioOverride = $request->input('scenario');
        }

        $updatedRequest = $this->policyService->submit($policyRequest, $scenarioOverride);

        return response()->json([
            'data'    => $this->formatPolicyRequest($updatedRequest),
            'message' => "Policy request submitted. Current status: {$updatedRequest->status}.",
        ]);
    }

    // ─── Formatting helpers ───────────────────────────────────────────────────

    /**
     * Format a policy request for the list / create / submit responses.
     */
    private function formatPolicyRequest(PolicyRequest $request): array
    {
        return [
            'id'                  => $request->id,
            'agent_id'            => $request->agent_id,
            'client_request_id'   => $request->client_request_id,
            'customer_name'       => $request->customer_name,
            'date_of_birth'       => $request->date_of_birth?->toDateString(),
            'destination'         => $request->destination,
            'trip_start'          => $request->trip_start?->toDateString(),
            'trip_end'            => $request->trip_end?->toDateString(),
            'travel_days'         => $request->travel_days,
            'plan_code'           => $request->plan_code,
            'sum_insured'         => $request->sum_insured,
            'premium'             => $request->premium,
            'status'              => $request->status,
            'provider_reference'  => $request->provider_reference,
            'policy_number'       => $request->policy_number,
            'attempt_count'       => $request->attempt_count,
            'created_at'          => $request->created_at?->toIso8601String(),
            'updated_at'          => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Format a policy request for the detail view, including history and attempts.
     */
    private function formatPolicyRequestDetailed(PolicyRequest $request): array
    {
        $data = $this->formatPolicyRequest($request);

        $data['state_history'] = $request->stateHistory
            ->sortBy('transitioned_at')
            ->values()
            ->map(fn ($h) => [
                'from'  => $h->from_status,
                'to'    => $h->to_status,
                'reason'=> $h->reason,
                'at'    => $h->transitioned_at?->toIso8601String(),
            ]);

        $data['issuance_attempts'] = $request->issuanceAttempts
            ->sortBy('attempt_number')
            ->values()
            ->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                'status'         => $a->attempt_status,
                'scenario'       => $a->scenario,
                'error_message'  => $a->error_message,
                'at'             => $a->created_at?->toIso8601String(),
            ]);

        return $data;
    }
}
