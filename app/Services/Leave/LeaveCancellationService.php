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
 * Employee cancel of pending leave, and admin cancel of confirmed leave.
 */
class LeaveCancellationService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveNotificationService $notificationService,
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * Employee-initiated cancellation while the leave is still pending approval.
     */
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

            $leave->loadMissing('leaveType');

            if (LeaveTypeRules::usesCredits((string) $leave->leaveType?->code)) {
                $this->balanceService->releaseReservation($leave, $cancellationReason);
            }

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

    /**
     * Admin-only cancellation of a confirmed leave (credits returned to available).
     */
    public function cancelConfirmedByAdmin(LeaveInfo $leave, Employee $admin, string $cancellationReason): LeaveInfo
    {
        $this->assertActorIsAdmin($admin);
        $this->assertLeaveIsAdminCancellable($leave);

        $leave = DB::transaction(function () use ($leave, $admin, $cancellationReason) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorIsAdmin($admin);
            $this->assertLeaveIsAdminCancellable($leave);

            $leave->update([
                'status' => LeaveStatus::Cancelled,
                'cancellation_reason' => $cancellationReason,
            ]);

            $leave->loadMissing('leaveType');

            if (LeaveTypeRules::usesCredits((string) $leave->leaveType?->code)) {
                $this->balanceService->reverseConfirmedLeave($leave, $cancellationReason);
            }

            if (LeaveTypeRules::grantsCreditsOnSubmit((string) $leave->leaveType?->code)) {
                $this->balanceService->reverseParentalEntitlement($leave, $cancellationReason);
            }

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $admin->id,
                'activity_type' => ActivityType::Cancelled,
                'remarks' => $cancellationReason,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        $this->notificationService->notifyEmployeeOnAdminCancel($leave, $admin, $cancellationReason);

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

    private function assertActorIsAdmin(Employee $admin): void
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can cancel a confirmed leave.');
        }
    }

    private function assertLeaveIsAdminCancellable(LeaveInfo $leave): void
    {
        if ($leave->status === LeaveStatus::PendingConversion) {
            throw ValidationException::withMessages([
                'leave' => [
                    'Leave with a pending conversion cannot be cancelled. Reject the conversion first, then cancel the confirmed leave.',
                ],
            ]);
        }

        if ($leave->status !== LeaveStatus::Confirmed) {
            throw ValidationException::withMessages([
                'leave' => ['Only confirmed leave can be cancelled by admin.'],
            ]);
        }
    }
}
