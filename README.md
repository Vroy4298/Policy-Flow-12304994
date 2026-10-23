# PolicyFlow — Travel Insurance Issuance & Reconciliation Engine

> Campus Engineering Assessment — TrippersShield Internship

A small, reliable REST API for a fictional travel-insurance distributor. Agents submit policy requests; the application validates them, calculates premiums, submits them to a mock insurer, and tracks issuance outcomes safely.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture](#2-architecture)
3. [Prerequisites & Setup](#3-prerequisites--setup)
4. [Database Setup](#4-database-setup)
5. [Running Tests](#5-running-tests)
6. [API Reference](#6-api-reference)
7. [Premium Calculation](#7-premium-calculation)
8. [Reliability Scenarios](#8-reliability-scenarios)
9. [Duplicate Prevention & Transactions](#9-duplicate-prevention--transactions)
10. [Mock Insurer & Reconciliation](#10-mock-insurer--reconciliation)
11. [Architecture File Guide](#11-architecture-file-guide)
12. [Interview Preparation](#12-interview-preparation)
13. [Known Limitations](#13-known-limitations)
14. [Assumptions](#14-assumptions)

---

## 1. Project Overview

PolicyFlow is a Laravel 12 REST API that implements a travel insurance policy issuance workflow:

```
Agent → POST /api/policy-requests → Validate & Calculate Premium → VALIDATED
                                                                        ↓
                             POST /api/policy-requests/{id}/submit → SUBMITTED → ISSUED
                                                                              ↘ FAILED (retriable)
                                                                              ↘ NEEDS_RECONCILIATION
```

**Stack:** PHP 8.4 · Laravel 12.70 · MariaDB 10.4 · PHPUnit 11 · Brick\Math

---

## 2. Architecture

### Simple Request Flow

```
HTTP Request
     │
     ▼
routes/api.php
     │
     ▼
PolicyRequestController (or MockInsurerController)
     │  validates input → 422 on error
     ▼
PolicyService
     │  DB transaction + locking
     ├── PremiumCalculator    (pure PHP, no DB)
     ├── MockInsurerService   (simulates external insurer)
     └── PolicyStateHistory   (append-only audit)
     │
     ▼
Eloquent Models → MySQL/MariaDB
```

### Database Tables

| Table | Purpose |
|---|---|
| `policy_requests` | Central entity — one row per logical request |
| `issuance_attempts` | Audit trail of every insurer API call |
| `provider_events` | Inbound callbacks with duplicate detection |
| `policy_state_histories` | Immutable status transition log |
| `mock_insurer_policies` | Mock insurer's own "internal" policy store |

### Policy Lifecycle States

```
RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED (terminal)
                              ↘ FAILED ↗ (retry)
                              ↘ NEEDS_RECONCILIATION → ISSUED (via reconciliation)
```

---

## 3. Prerequisites & Setup

### Required

- PHP 8.2 or newer (tested on PHP 8.4.3)
- Composer 2.x
- MySQL 8+ or MariaDB 10.4+ running locally
- Git

### Clone and install

```bash
git clone https://github.com/<your-github-username>/policyflow-<candidate-id>.git
cd policyflow-<candidate-id>

# Install PHP dependencies
composer install
```

### Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=your_password_here

MOCK_INSURER_SCENARIO=SUCCESS
```

### Start the development server

```bash
php artisan serve
# → http://127.0.0.1:8000
```

---

## 4. Database Setup

```bash
# Create the main and test databases (MySQL/MariaDB)
mysql -u root -p -e "
  CREATE DATABASE IF NOT EXISTS policyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS policyflow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"

# Run migrations on the main database
php artisan migrate

# The test database is migrated automatically when running tests (RefreshDatabase)
```

---

## 5. Running Tests

```bash
# Run all tests
php artisan test

# Run only unit tests (no database required)
php artisan test --filter PremiumCalculatorTest

# Run only feature tests
php artisan test --filter PolicyRequestTest

# Run with verbose output
php artisan test --verbose
```

**Expected output (all 41 tests should pass):**

```
Tests:    41 passed (135 assertions)
Duration: ~4s
```

The test database is configured in `phpunit.xml`. Tests use `RefreshDatabase` to reset the database between each test. The `policyflow_test` database must exist (created in step 4 above).

---

## 6. API Reference

### Base URL: `http://127.0.0.1:8000`

All endpoints are prefixed with `/api`. All requests and responses use JSON.

---

### POST /api/policy-requests

Create a new policy request. If the same `agent_id` + `client_request_id` combination already exists, the existing request is returned safely (idempotent).

**HTTP Status:**
- `201 Created` — new request created
- `200 OK` — existing request returned (idempotent repeat)
- `422 Unprocessable Entity` — validation error

**Request body:**

```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**curl example:**

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
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
  }'
```

**Response (201):**

```json
{
  "data": {
    "id": 1,
    "agent_id": "AGENT-17",
    "client_request_id": "AGT-2026-0001",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "travel_days": 5,
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "premium": "225.00",
    "status": "VALIDATED",
    "provider_reference": null,
    "policy_number": null,
    "attempt_count": 0,
    "created_at": "2026-11-10T10:00:00+00:00",
    "updated_at": "2026-11-10T10:00:00+00:00"
  },
  "created": true,
  "message": "Policy request created successfully."
}
```

---

### GET /api/policy-requests

List policy requests with optional status filter and pagination.

**Query parameters:**
- `?status=VALIDATED` — filter by status
- `?per_page=15` — items per page (default 15, max 100)
- `?page=2` — page number

**curl example:**

```bash
# List all requests
curl http://127.0.0.1:8000/api/policy-requests \
  -H "Accept: application/json"

# Filter by status
curl "http://127.0.0.1:8000/api/policy-requests?status=ISSUED&per_page=5" \
  -H "Accept: application/json"
```

**Response (200):**

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

### GET /api/policy-requests/{id}

Retrieve a specific policy request with full state history and attempt log.

**curl example:**

```bash
curl http://127.0.0.1:8000/api/policy-requests/1 \
  -H "Accept: application/json"
```

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "status": "ISSUED",
    "premium": "225.00",
    "policy_number": "POL-20261110-AB12CD34",
    "state_history": [
      {"from": null, "to": "RECEIVED", "reason": "Policy request received.", "at": "2026-11-10T10:00:00+00:00"},
      {"from": "RECEIVED", "to": "VALIDATED", "reason": "All fields validated.", "at": "2026-11-10T10:00:01+00:00"},
      {"from": "VALIDATED", "to": "SUBMITTED", "reason": "Submitting to insurer. Attempt #1.", "at": "2026-11-10T10:00:02+00:00"},
      {"from": "SUBMITTED", "to": "ISSUED", "reason": "Policy issued by insurer.", "at": "2026-11-10T10:00:03+00:00"}
    ],
    "issuance_attempts": [
      {"attempt_number": 1, "status": "SUCCESS", "scenario": "SUCCESS", "at": "2026-11-10T10:00:02+00:00"}
    ]
  }
}
```

---

### POST /api/policy-requests/{id}/submit

Submit a validated policy request to the (mock) insurer.

**Optional body (local/testing only):**

```json
{
  "scenario": "SUCCESS"
}
```

Available scenarios (dev/test only): `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`

**curl examples:**

```bash
# Standard submit (uses MOCK_INSURER_SCENARIO from .env)
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json"

# Submit with specific scenario (dev only)
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}'
```

---

### POST /api/mock-insurer/callback

Receive a provider event (callback) from the insurer.

**Request body:**

```json
{
  "event_id": "EVT-20261110-UNIQUE123",
  "event_type": "ISSUED",
  "provider_reference": "PR-1-AB12CD34",
  "policy_number": "POL-20261110-AB12CD34"
}
```

**curl example:**

```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-20261110-UNIQUE123",
    "event_type": "ISSUED",
    "provider_reference": "PR-1-AB12CD34",
    "policy_number": "POL-20261110-AB12CD34"
  }'
```

---

## 7. Premium Calculation

**Formula:**

```
premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
```

| Plan | Daily Rate |
|---|---|
| BASIC | ₹12 |
| PLUS | ₹20 |
| PREMIUM | ₹30 |

| Age on Trip Start | Multiplier |
|---|---|
| 0–17 | 1.10 |
| 18–60 | 1.00 |
| 61–70 | 1.50 |
| 71+ | 2.00 |

| Destination | Multiplier |
|---|---|
| DOMESTIC | 1.00 |
| INTERNATIONAL | 1.80 |

| Sum Insured | Multiplier |
|---|---|
| ₹1,00,000 | 1.00 |
| ₹3,00,000 | 1.25 |
| ₹5,00,000 | 1.50 |

**Travel days:** `trip_end − trip_start + 1` (inclusive, minimum 1)

**Rounding:** Final premium is rounded to 2 decimal places using `HALF_UP` rounding via the `brick/math` library (arbitrary-precision decimal arithmetic — no floating-point errors).

### Reference Example (from assessment)

```
Plan:         PLUS (₹20/day)
Trip:         2026-11-10 to 2026-11-14 (5 days)
Age:          28 (multiplier 1.00)
Destination:  INTERNATIONAL (multiplier 1.80)
Sum Insured:  ₹3,00,000 (multiplier 1.25)

Premium = 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00 ✓
```

---

## 8. Reliability Scenarios

### Case A: Duplicate Intake (IMPLEMENTED & TESTED)

**Problem:** The same agent submits the same policy request twice (network retry, user double-click, or concurrent requests).

**Solution:**
1. The `policy_requests` table has a `UNIQUE(agent_id, client_request_id)` database constraint.
2. We wrap the INSERT in a transaction and catch the unique constraint violation (MySQL error 1062).
3. On violation, we SELECT the existing record and return it with HTTP 200.
4. This is race-condition-safe: even two simultaneous requests cannot create two rows.

**Test:** `test_duplicate_intake_returns_200_and_same_request`

---

### Case B: Timeout After Success (IMPLEMENTED & TESTED)

**Problem:** The insurer creates a policy, but the network times out before returning the response. A naive retry would issue a second policy.

**Solution:**
1. The `mock_insurer_policies` table records every policy the insurer creates (with `response_delivered = false` for timed-out responses).
2. On timeout, we set status to `NEEDS_RECONCILIATION`.
3. `PolicyService::reconcile()` calls `MockInsurerService::checkStatus(provider_reference)`.
4. If the insurer has a record, we use its `policy_number` and set status to `ISSUED` — no second issuance.
5. If the insurer has no record, it's safe to retry.

**Test:** `test_timeout_after_issue_recovers_via_reconciliation`

---

### Case C: Duplicate Callback (IMPLEMENTED & TESTED)

**Problem:** The insurer sends the same callback event twice.

**Solution:**
1. The `provider_events` table has a `UNIQUE(provider_event_id)` constraint.
2. We attempt to INSERT the event. If the INSERT fails with a duplicate key error, the event is already recorded.
3. We return HTTP 200 without re-processing. The policy state is not changed again.

**Test:** `test_duplicate_callback_is_handled_idempotently`

---

### Case D: Retry After Temporary Failure (IMPLEMENTED & TESTED)

**Solution:** FAILED status is retryable (`canBeSubmitted()` returns true for FAILED). Each retry creates a new `issuance_attempts` row. The `provider_reference` is preserved so reconciliation can still work.

**Test:** `test_temporary_failure_can_be_retried`

---

## 9. Duplicate Prevention & Transaction Strategy

### Database Constraint

The critical constraint is:

```sql
UNIQUE KEY (agent_id, client_request_id)
```

This constraint lives in the database, not the application. Even if two HTTP requests arrive simultaneously and both pass any application-level check, only one INSERT will succeed. The other will receive a `SQLSTATE[23000]` unique constraint violation.

### Transaction Boundaries

| Operation | Transaction | Locking |
|---|---|---|
| `createOrFind()` | Yes — INSERT + state history are atomic | None (unique constraint handles concurrency) |
| `submit()` | Yes — all state changes + attempt record | `lockForUpdate()` on the policy_request row |
| `processCallback()` | Yes — event INSERT + state change | `lockForUpdate()` on the policy_request row |
| `reconcile()` | Yes — reconciliation + state change | `lockForUpdate()` on the policy_request row |

### Why `lockForUpdate()`?

When two simultaneous HTTP requests both call `submit()` on the same policy_request, we need to prevent double-issuance:
1. Request A acquires the lock, sees status = VALIDATED, submits to insurer, sets ISSUED.
2. Request B waits for the lock. When it gets it, status = ISSUED, so it returns early without submitting again.

---

## 10. Mock Insurer & Reconciliation

### Scenarios

| Scenario | Behaviour | How to trigger |
|---|---|---|
| `SUCCESS` | Returns policy number immediately | Default. Set `MOCK_INSURER_SCENARIO=SUCCESS` in `.env` |
| `TEMPORARY_FAILURE` | Returns retriable error | `MOCK_INSURER_SCENARIO=TEMPORARY_FAILURE` |
| `TIMEOUT_AFTER_ISSUE` | Creates policy in DB, throws timeout exception | `MOCK_INSURER_SCENARIO=TIMEOUT_AFTER_ISSUE` |
| `DELAYED_CALLBACK` | Returns "pending", requires manual callback | `MOCK_INSURER_SCENARIO=DELAYED_CALLBACK` |
| `DUPLICATE_CALLBACK` | Like SUCCESS but expect duplicate callback | `MOCK_INSURER_SCENARIO=DUPLICATE_CALLBACK` |

Per-request override (local/testing only): pass `"scenario": "TIMEOUT_AFTER_ISSUE"` in the submit request body.

### Reconciliation

The `mock_insurer_policies` table serves as the insurer's internal database. `MockInsurerService::checkStatus(provider_reference)` looks up this table — simulating a "policy status lookup" API that real insurers expose.

When `TIMEOUT_AFTER_ISSUE` fires:
1. The insurer writes to `mock_insurer_policies` with `response_delivered = false`.
2. Our app catches the `\RuntimeException` (timeout) and sets status to `NEEDS_RECONCILIATION`.
3. Call `PolicyService::reconcile()` — it queries `checkStatus()`, finds the policy, and sets `ISSUED`.

---

## 11. Architecture File Guide

| File | Purpose |
|---|---|
| `app/Enums/PolicyStatus.php` | All valid states and allowed transitions |
| `app/Services/PremiumCalculator.php` | Pure PHP premium formula — no DB dependency |
| `app/Services/MockInsurerService.php` | 5 deterministic insurer scenarios |
| `app/Services/PolicyService.php` | Core business logic: create, submit, callback, reconcile |
| `app/Http/Controllers/Api/PolicyRequestController.php` | 4 API endpoints |
| `app/Http/Controllers/Api/MockInsurerController.php` | Callback endpoint |
| `app/Models/PolicyRequest.php` | Central entity |
| `app/Models/IssuanceAttempt.php` | Per-attempt audit |
| `app/Models/ProviderEvent.php` | Inbound callback records |
| `app/Models/PolicyStateHistory.php` | Immutable state transition log |
| `app/Models/MockInsurerPolicy.php` | Mock insurer's internal store |
| `database/migrations/` | All 5 custom migrations |
| `routes/api.php` | All 5 API routes |
| `tests/Unit/PremiumCalculatorTest.php` | 16 pure unit tests |
| `tests/Feature/PolicyRequestTest.php` | 23 feature tests using real DB |

---

## 12. Interview Preparation

### Key Functions to Know

| Function | File | What it does |
|---|---|---|
| `PremiumCalculator::calculate()` | `Services/PremiumCalculator.php` | Computes premium using Brick\Math |
| `PolicyService::createOrFind()` | `Services/PolicyService.php` | Idempotent intake with catch-duplicate pattern |
| `PolicyService::submit()` | `Services/PolicyService.php` | Submits to insurer with pessimistic locking |
| `PolicyService::processCallback()` | `Services/PolicyService.php` | Idempotent callback processing |
| `PolicyService::reconcile()` | `Services/PolicyService.php` | Recovers after timeout |
| `PolicyService::transitionState()` | `Services/PolicyService.php` | Validates and applies state transitions |
| `PolicyStatus::canTransitionTo()` | `Enums/PolicyStatus.php` | Enforces allowed transitions |
| `MockInsurerService::issue()` | `Services/MockInsurerService.php` | Simulates insurer call |
| `MockInsurerService::checkStatus()` | `Services/MockInsurerService.php` | Reconciliation lookup |

### Likely Interview Questions

**Q: Why use a database UNIQUE constraint instead of checking in code?**
A: Application-level checks have a race condition: two concurrent requests can both pass a SELECT check and then both INSERT. Only a database constraint is truly atomic — exactly one INSERT will succeed and the other will fail with a duplicate key error.

**Q: What is the error code you catch for duplicate key violations?**
A: MySQL/MariaDB error code `1062`. We check `$e->errorInfo[1] === 1062` in `isDuplicateKeyException()`.

**Q: What does `lockForUpdate()` do and why use it in submit()?**
A: `lockForUpdate()` is a pessimistic write lock on the selected rows for the duration of the transaction. Without it, two simultaneous submit requests could both read status=VALIDATED, both pass the canBeSubmitted() check, and both call the insurer — issuing two policies. With the lock, the second request waits until the first commits, then sees status=ISSUED and returns early.

**Q: How does reconciliation prevent double-issuance after a timeout?**
A: The mock insurer writes to `mock_insurer_policies` before throwing the timeout exception. Reconciliation queries this table by `provider_reference`. If a record exists, we know the insurer already issued the policy and we use its `policy_number` — no second API call is made.

**Q: Why use `brick/math` instead of PHP's native arithmetic?**
A: PHP floats use IEEE 754 binary representation. `0.1 + 0.2` in PHP gives `0.30000000000000004`. For financial calculations, even tiny errors compound. `brick/math` uses arbitrary-precision decimal arithmetic and rounds only at the very end using `HALF_UP`.

**Q: What is `after_or_equal:trip_start` in validation?**
A: It's Laravel's built-in date comparison validator. It compares `trip_end` against `trip_start` as dates and fails if `trip_end` is before `trip_start`. We use this instead of `gte` which compares values as numbers.

**Q: What are the valid state transitions?**
A: See `PolicyStatus::VALID_TRANSITIONS`. Notable: ISSUED is terminal (nothing can transition out of it). FAILED can go back to SUBMITTED (retry). NEEDS_RECONCILIATION can go to SUBMITTED, ISSUED, or FAILED.

---

## 13. Known Limitations

1. **No real insurer integration** — The mock insurer is entirely local. A real implementation would use HTTP clients with retry logic, circuit breakers, and proper timeout handling.

2. **No authentication** — The callback endpoint has no signature verification. In production, use HMAC signatures or OAuth tokens to validate callbacks.

3. **No async processing** — Policy submission is synchronous. Under high load, a queue-based system (Laravel Queues) would be more appropriate.

4. **NEEDS_RECONCILIATION requires manual trigger** — There is no background reconciliation job. A `php artisan policyflow:reconcile` command would be the next feature to build.

5. **MariaDB vs MySQL** — The application is tested on MariaDB 10.4 (via XAMPP), which is fully compatible with MySQL 8 for all features used here.

6. **No rate limiting** — The API has no rate limiting. In production, Laravel's built-in throttle middleware should be applied.

---

## 14. Assumptions

1. All currency amounts are in Indian Rupees (₹). The `premium` column stores the numeric value only (e.g., `225.00`), not a currency symbol.
2. Age is calculated as of the `trip_start` date using whole years only (floor, not rounding).
3. Travel days are inclusive of both start and end dates. A one-day trip has `travel_days = 1`.
4. The mock insurer's `DELAYED_CALLBACK` scenario requires the callback to be sent manually or via tests — there is no background job that auto-delivers it.
5. A policy in `ISSUED` state is permanently terminal — even a subsequent `FAILED` callback cannot change it.
6. The `scenario` override in the submit request body is only accepted when `APP_ENV` is `local` or `testing`. This ensures production cannot be manipulated.
