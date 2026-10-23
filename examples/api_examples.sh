# PolicyFlow — curl Examples
# Run these commands against a running local server: php artisan serve

BASE_URL="http://127.0.0.1:8000"

# ─── 1. Create a new policy request ──────────────────────────────────────────

echo "=== Create Policy Request (expect 201) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python -m json.tool

echo ""
echo "=== Repeat same request (expect 200, created=false) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python -m json.tool

# ─── 2. Get a specific request ────────────────────────────────────────────────

echo ""
echo "=== Get policy request by ID ==="
curl -s "$BASE_URL/api/policy-requests/1" \
  -H "Accept: application/json" | python -m json.tool

# ─── 3. List with filter ──────────────────────────────────────────────────────

echo ""
echo "=== List VALIDATED requests ==="
curl -s "$BASE_URL/api/policy-requests?status=VALIDATED&per_page=5" \
  -H "Accept: application/json" | python -m json.tool

# ─── 4. Submit to insurer (SUCCESS scenario) ──────────────────────────────────

echo ""
echo "=== Submit (SUCCESS) ==="
curl -s -X POST "$BASE_URL/api/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}' | python -m json.tool

# ─── 5. Submit with TIMEOUT scenario then reconcile ──────────────────────────

echo ""
echo "=== Create new request for timeout test ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-TIMEOUT",
    "agent_id": "AGENT-17",
    "customer_name": "Timeout Test",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-12",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }' | python -m json.tool

echo ""
echo "=== Submit with TIMEOUT_AFTER_ISSUE (expect NEEDS_RECONCILIATION) ==="
curl -s -X POST "$BASE_URL/api/policy-requests/2/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}' | python -m json.tool

# ─── 6. Validation error examples ────────────────────────────────────────────

echo ""
echo "=== Invalid plan code (expect 422) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-BAD-001",
    "agent_id": "AGENT-17",
    "customer_name": "Bad Plan",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-12",
    "plan_code": "GOLD",
    "sum_insured": 100000
  }' | python -m json.tool

echo ""
echo "=== Invalid dates (trip_end before trip_start, expect 422) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-BAD-002",
    "agent_id": "AGENT-17",
    "customer_name": "Bad Dates",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-11-14",
    "trip_end": "2026-11-10",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }' | python -m json.tool

# ─── 7. Callback example ─────────────────────────────────────────────────────

echo ""
echo "=== Send callback (DELAYED_CALLBACK flow) ==="
echo "First submit with DELAYED_CALLBACK:"
curl -s -X POST "$BASE_URL/api/policy-requests/2/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "DELAYED_CALLBACK"}' | python -m json.tool

# Get the provider_reference from step above, then send callback:
echo "Then send callback:"
curl -s -X POST "$BASE_URL/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-20261110-UNIQUE001",
    "event_type": "ISSUED",
    "provider_reference": "PR-2-XXXXXXXX",
    "policy_number": "POL-20261110-TESTABCD"
  }' | python -m json.tool
