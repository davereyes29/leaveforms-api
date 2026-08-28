<?php

use App\Http\Controllers\AdminActivityController;
use App\Http\Controllers\AdminAnnualEntitlementController;
use App\Http\Controllers\AdminBalanceController;
use App\Http\Controllers\AdminContractualEntitlementController;
use App\Http\Controllers\AdminLeaveController;
use App\Http\Controllers\AdminMonthlyAttendanceReportController;
use App\Http\Controllers\AdminNewlyRegularizedController;
use App\Http\Controllers\AdminReminderController;
use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveActivityController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveConversionController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\TeamCalendarController;
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

// Local only — returns portal userID hash for emp_id (frontend sets the cookie).
Route::post('/dev/login', DevLoginController::class);

Route::middleware('portal.auth')->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/groups/{group}/members', [GroupMemberController::class, 'index']);
    Route::get('/balances', [LeaveBalanceController::class, 'index']);
    Route::get('/activities', [LeaveActivityController::class, 'index']);
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::get('/leaves/{leave}', [LeaveController::class, 'show']);
    // POST is preferred for multipart/form-data (PHP does not parse files on PUT).
    // PUT still works for JSON bodies without a file.
    Route::match(['put', 'post'], '/leaves/{leave}', [LeaveController::class, 'update']);
    Route::post('/leaves/{leave}/attachments', [LeaveController::class, 'storeAttachments']);
    Route::get('/leaves/{leave}/attachments/{attachment}', [LeaveController::class, 'downloadAttachment']);
    Route::delete('/leaves/{leave}/attachments/{attachment}', [LeaveController::class, 'destroyAttachment']);
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::post('/leaves/{leave}/conversion', [LeaveConversionController::class, 'store']);
    Route::post('/leaves/{leave}/approve', [LeaveApprovalController::class, 'approve']);
    Route::post('/leaves/{leave}/reject', [LeaveApprovalController::class, 'reject']);
    Route::post('/leaves/{leave}/reminders', [AdminReminderController::class, 'storeForLeave']);
    Route::get('/approvals', [LeaveApprovalController::class, 'index']);
    Route::get('/approvals/pending', [LeaveApprovalController::class, 'pending']);
    Route::get('/team/calendar', [TeamCalendarController::class, 'index']);

    Route::get('/admin/leaves', [AdminLeaveController::class, 'index']);
    Route::get('/admin/activities', [AdminActivityController::class, 'index']);
    Route::get('/admin/groups', [AdminLeaveController::class, 'groups']);
    Route::get('/admin/departments', [AdminLeaveController::class, 'departments']);
    Route::get('/admin/employees', [AdminLeaveController::class, 'employees']);
    Route::get('/admin/reports/monthly-attendance', AdminMonthlyAttendanceReportController::class);
    Route::get('/admin/balances', [AdminBalanceController::class, 'index']);
    Route::post('/admin/balances', [AdminBalanceController::class, 'store']);
    Route::get('/admin/annual-entitlements', [AdminAnnualEntitlementController::class, 'index']);
    Route::post('/admin/annual-entitlements', [AdminAnnualEntitlementController::class, 'store']);
    Route::get('/admin/newly-regularized', [AdminNewlyRegularizedController::class, 'index']);
    Route::post('/admin/newly-regularized', [AdminNewlyRegularizedController::class, 'store']);
    Route::get('/admin/contractual-entitlements', [AdminContractualEntitlementController::class, 'index']);
    Route::post('/admin/contractual-entitlements', [AdminContractualEntitlementController::class, 'store']);
    Route::get('/admin/reminders', [AdminReminderController::class, 'show']);
    Route::post('/admin/reminders', [AdminReminderController::class, 'store']);
    Route::get('/conversions/pending', [LeaveConversionController::class, 'pending']);
    Route::post('/conversions/{conversion}/approve', [LeaveConversionController::class, 'approve']);
    Route::post('/conversions/{conversion}/reject', [LeaveConversionController::class, 'reject']);
    Route::post('/conversions/{conversion}/attachments', [LeaveConversionController::class, 'storeAttachments']);
    Route::get('/conversions/{conversion}/attachments/{attachment}', [LeaveConversionController::class, 'downloadAttachment']);
    Route::delete('/conversions/{conversion}/attachments/{attachment}', [LeaveConversionController::class, 'destroyAttachment']);
});
