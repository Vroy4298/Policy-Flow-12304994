<?php

use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — PolicyFlow
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically (configured in bootstrap/app.php).
|
| Endpoint summary:
|   POST   /api/policy-requests              Create or safely return a policy request
|   GET    /api/policy-requests              List requests (with ?status= and pagination)
|   GET    /api/policy-requests/{id}         Retrieve a specific request + audit trail
|   POST   /api/policy-requests/{id}/submit  Submit to (mock) insurer
|   POST   /api/mock-insurer/callback        Receive an insurer callback event
|
*/

// ─── Policy Request endpoints ─────────────────────────────────────────────────
Route::post('/policy-requests', [PolicyRequestController::class, 'createOrFind']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit']);

// ─── Mock insurer callback endpoint ──────────────────────────────────────────
Route::post('/mock-insurer/callback', [MockInsurerController::class, 'callback']);
