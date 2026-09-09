<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveInfo;

/**
 * Finalizes a leave after all approval requirements are satisfied.
 */
class LeaveFinalizationService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * Must be called inside an outer DB::transaction.
     */
    public function finalize(LeaveInfo $leave, Employee $approver): LeaveInfo
    {
        $leave->update([
            'status' => LeaveStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $leave->loadMissing('leaveType');

        if (LeaveTypeRules::usesCredits((string) $leave->leaveType?->code)) {
            $this->balanceService->confirmPaidDays($leave);
        }

        LeaveActivityHistory::query()->create([
            'leave_id' => $leave->id,
            'performed_by_emp_id' => $approver->id,
            'activity_type' => ActivityType::LeaveConfirmed,
        ]);

        return $leave->fresh();
    }
}
