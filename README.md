# PolicyFlow — Travel Insurance Issuance & Reconciliation Engine

A REST API for travel insurance issuance and reconciliation built with Laravel 12 and MySQL.

---

## Prerequisites

- PHP >= 8.2 with `bcmath`, `pdo_mysql` extensions
- Composer 2.x
- MySQL 8.x

---

## Quick Setup

1. **Clone & install dependencies**
   ```bash
   git clone https://github.com/Vroy4298/Policy-Flow-12304994.git
   cd Policy-Flow-12304994
   composer install
   ```

2. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Update `.env` with your MySQL credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=policyflow
   DB_USERNAME=root
   DB_PASSWORD=your_password
   ```

3. **Run migrations & seed test data**
   ```bash
   php artisan migrate --seed
   ```

4. **Start the local server**
   ```bash
   php artisan serve
   ```
   The API is available at `http://127.0.0.1:8000/api`.

---

## Running Automated Tests

Run the full test suite (41 tests, 135 assertions):

```bash
php artisan test
```

Tests run in-memory using SQLite and require no live external services.

---

## API Endpoints

All endpoints accept and return `application/json`.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/policies` | Submit a new policy request (idempotent) |
| `GET` | `/api/policies/{id}` | Fetch policy details and issuance attempt audit log |
| `POST` | `/api/policies/{id}/issue` | Trigger downstream issuance with mock insurer |
| `POST` | `/api/policies/{id}/reconcile` | Reconcile policies in `PENDING_CONFIRMATION` or `NEEDS_RECONCILIATION` |
| `GET` | `/api/policies` | List policies with optional `?status=` and `?agent_id=` filters |

---

## Quick API Example

### 1. Create Policy
```bash
curl -X POST http://127.0.0.1:8000/api/policies \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "AG-001",
    "client_request_id": "REQ-2026-001",
    "insured_name": "Rohan Sharma",
    "insured_age": 28,
    "destination": "SG",
    "trip_start_date": "2026-11-01",
    "trip_end_date": "2026-11-05",
    "plan_code": "PLUS",
    "sum_insured": 500000
  }'
```

### 2. Issue Policy
```bash
curl -X POST http://127.0.0.1:8000/api/policies/1/issue \
  -H "Content-Type: application/json"
```

### 3. Reconcile Policy
```bash
curl -X POST http://127.0.0.1:8000/api/policies/1/reconcile \
  -H "Content-Type: application/json"
```

A complete shell script with curl examples is located at [`examples/api_examples.sh`](examples/api_examples.sh), and an importable Postman collection is at [`examples/PolicyFlow.postman_collection.json`](examples/PolicyFlow.postman_collection.json).

---

## Core Engineering Decisions

1. **Idempotency & Duplicate Prevention**: Compound `UNIQUE(agent_id, client_request_id)` constraint in MySQL. Repeated submissions return the existing record (`200 OK`) without re-calculating or creating duplicates.
2. **Concurrency Safety**: Critical state transitions use database transactions with pessimistic row locking (`lockForUpdate()`) to prevent race conditions during issuance and reconciliation.
3. **Decimal-Safe Pricing**: All premium calculations use PHP's `bcmath` extension (`bcmul`, `bcdiv`) to eliminate IEEE-754 floating-point rounding errors.
4. **State Machine Integrity**: Policies follow a strict lifecycle (`RECEIVED` -> `VALIDATED` -> `SUBMITTED` -> `ISSUED` / `FAILED` / `PENDING_CONFIRMATION` -> `NEEDS_RECONCILIATION`). Invalid transitions throw a `409 Conflict`.
5. **Audit Trail**: Every interaction with the downstream insurer is logged in `issuance_attempts` with payload, response status, insurer reference, and latency.
