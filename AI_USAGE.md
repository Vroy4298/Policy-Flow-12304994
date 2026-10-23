# AI Usage Disclosure — PolicyFlow

This file honestly discloses my use of AI tools during this assessment,
in accordance with the assessment's AI usage policy.

---

## Tools Used

- **Google Antigravity (Claude Sonnet 4.6 Thinking)** — Used as the primary AI coding assistant throughout the implementation.
- **Official Laravel 12 Documentation** — Read directly for reference on routing, validation, and middleware configuration.
- **PHP Manual** — Consulted for PHP 8.4 DateTime behaviour.

---

## Concrete Ways AI Helped

### 1. Scaffolding Boilerplate Code

AI generated the initial structure for migrations, models, and controllers, saving time on repetitive Laravel boilerplate (e.g., `fillable` arrays, `casts`, `belongsTo`/`hasMany` relationships). This let me focus on the business logic.

### 2. Writing Comprehensive Test Cases

AI helped draft all 23 feature test methods and 16 unit tests. Rather than writing each test from scratch, I described the scenario in the prompt ("test that a timeout followed by reconciliation recovers the policy number without re-issuing") and AI generated the test structure. I then verified each test actually exercised the correct code paths.

### 3. Explaining and Structuring the Idempotency Strategy

When I described the concurrency problem (two simultaneous requests with the same `agent_id + client_request_id`), AI explained the "insert-and-catch" pattern (try INSERT, catch unique constraint violation) and why it is safer than "check-then-insert" (SELECT + INSERT). This shaped the design of `PolicyService::createOrFind()`.

---

## Incorrect or Incomplete AI Suggestion

**Issue: Carbon's `diffInDays()` and `diffInYears()` return floats in PHP 8.4**

AI initially implemented the `PremiumCalculator` using `Carbon::parse($date)->diffInDays()` and `->diffInYears()`. In PHP 8.4, Carbon 3.x changed these methods to return `float` instead of `int`. When run, `diffInDays('2026-11-10', '2026-11-14')` returned `-4` (negative! because direction was reversed) and `diffInYears()` returned `28.586...` instead of `28`.

As a result, the reference premium test failed: it computed `₹45.00` instead of `₹225.00`.

**How I identified it:** The test `test_reference_example_from_assessment_225_rupees` failed with the wrong premium value. I ran a diagnostic PHP script to print the actual Carbon return values and confirmed the float/negative issue.

**How I fixed it:** Replaced all Carbon date arithmetic with PHP's native `\DateTime::diff()`, which reliably returns `DateInterval` with integer `->days` and `->y` properties. The fix was validated by re-running all 16 unit tests and confirming all passed.

---

## How I Verified the Output

1. **Every test was run locally** with `php artisan test`. The actual output (41 passed, 135 assertions) is what is reported in this repository — not a claimed result.

2. **The reference premium example (₹225.00)** has a dedicated test `test_reference_example_from_assessment_225_rupees` which I verified passes.

3. **The reliability scenarios** (duplicate intake, timeout reconciliation, duplicate callback) each have dedicated tests that I traced through the code to ensure they exercise the actual constraint/locking/transaction logic — not just happy-path assertions.

4. **Database constraints** were verified by inspecting the migration files and running `php artisan migrate` successfully, then confirming via MySQL CLI that the unique constraints exist on the tables.

5. **AI-suggested code that failed tests was fixed before being committed** — no failing tests are hidden or skipped.
