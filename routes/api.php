<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Leave Forms API
|--------------------------------------------------------------------------
|
| All leave endpoints will live under /api. The React frontend (separate repo)
| calls these routes with credentials: 'include' so the portal cookie is sent.
|
*/

Route::get('/health', HealthController::class);

Route::middleware('portal.auth')->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/balances', [LeaveBalanceController::class, 'index']);
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    // POST is preferred for multipart/form-data (PHP does not parse files on PUT).
    // PUT still works for JSON bodies without a file.
    Route::match(['put', 'post'], '/leaves/{leave}', [LeaveController::class, 'update']);
    Route::get('/leaves/{leave}/attachment', [LeaveController::class, 'attachment']);
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::post('/leaves/{leave}/approve', [LeaveApprovalController::class, 'approve']);
    Route::post('/leaves/{leave}/reject', [LeaveApprovalController::class, 'reject']);
    Route::get('/approvals/pending', [LeaveApprovalController::class, 'pending']);
});
