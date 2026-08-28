<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee-initiated cancellation while a leave is still open.
 */
class LeaveCancellationService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    public function cancel(LeaveInfo $leave, Employee $employee, string $cancellationReason): LeaveInfo
    {
        $this->assertEmployeeCanCancel($leave, $employee);
        $this->assertLeaveIsCancellable($leave);

        $leave = DB::transaction(function () use ($leave, $employee, $cancellationReason) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEmployeeCanCancel($leave, $employee);
            $this->assertLeaveIsCancellable($leave);

            $leave->update([
                'status' => LeaveStatus::Cancelled,
                'cancellation_reason' => $cancellationReason,
            ]);

            $this->balanceService->releaseReservation($leave, $cancellationReason);

            $leave->loadMissing('leaveType');

            if (LeaveTypeRules::grantsCreditsOnSubmit((string) $leave->leaveType?->code)) {
                $this->balanceService->reverseParentalEntitlement($leave, $cancellationReason);
            }

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $employee->id,
                'activity_type' => ActivityType::Cancelled,
                'remarks' => $cancellationReason,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        $this->notificationService->notifyApproversOnCancel($leave, $cancellationReason);

        return $leave;
    }

    private function assertEmployeeCanCancel(LeaveInfo $leave, Employee $employee): void
    {
        if ((int) $leave->emp_id !== (int) $employee->id) {
            throw new AuthorizationException('You can only cancel your own leave requests.');
        }
    }

    private function assertLeaveIsCancellable(LeaveInfo $leave): void
    {
        if ($leave->status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => ['Only pending leave can be cancelled.'],
            ]);
        }
    }
}
