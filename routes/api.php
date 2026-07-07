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
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::post('/leaves/{leave}/approve', [LeaveApprovalController::class, 'approve']);
    Route::post('/leaves/{leave}/reject', [LeaveApprovalController::class, 'reject']);
    Route::get('/approvals/pending', [LeaveApprovalController::class, 'pending']);
});
