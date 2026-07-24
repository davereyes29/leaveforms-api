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

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $employee->id,
                'activity_type' => ActivityType::Cancelled,
                'remarks' => $cancellationReason,
            ]);

            return $leave->fresh(['leaveType', 'approvalRequirements.history']);
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
        if (! in_array($leave->status, [LeaveStatus::PendingApproval, LeaveStatus::PartiallyApproved], true)) {
            throw ValidationException::withMessages([
                'leave' => ['Only pending or partially approved leave can be cancelled.'],
            ]);
        }
    }
}
