<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\ConversionStatus;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * VL → SL conversion after the employee returns with a med cert.
 */
class LeaveConversionService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveListService $listService,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    public function request(LeaveInfo $leave, Employee $employee, UploadedFile $medCert, string $reason): LeaveConversion
    {
        $this->assertEmployeeOwnsLeave($leave, $employee);
        $this->assertLeaveIsConvertible($leave);

        $conversion = DB::transaction(function () use ($leave, $employee, $medCert, $reason) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->with(['leaveType', 'conversion'])
                ->firstOrFail();

            $this->assertEmployeeOwnsLeave($leave, $employee);
            $this->assertLeaveIsConvertible($leave);

            $existing = $leave->conversion;

            if ($existing !== null && $existing->status === ConversionStatus::PendingReview) {
                throw ValidationException::withMessages([
                    'leave' => ['A conversion request is already pending review for this leave.'],
                ]);
            }

            if ($existing !== null && $existing->status === ConversionStatus::Approved) {
                throw ValidationException::withMessages([
                    'leave' => ['This leave has already been converted to sick leave.'],
                ]);
            }

            $vlType = LeaveType::query()->where('code', 'VL')->firstOrFail();
            $slType = LeaveType::query()->where('code', 'SL')->firstOrFail();

            if ((int) $leave->leave_type_id !== (int) $vlType->id) {
                throw ValidationException::withMessages([
                    'leave' => ['Only Vacation Leave can be converted to Sick Leave.'],
                ]);
            }

            if ($existing !== null) {
                $this->attachmentService->delete($existing->med_cert_attachment);
            }

            $path = $this->attachmentService->storeMedCert($leave, $medCert);

            if ($existing !== null) {
                $existing->update([
                    'from_leave_type_id' => $vlType->id,
                    'to_leave_type_id' => $slType->id,
                    'med_cert_attachment' => $path,
                    'reason' => $reason,
                    'status' => ConversionStatus::PendingReview,
                    'reviewed_by_emp_id' => null,
                    'remarks' => null,
                    'reviewed_at' => null,
                ]);

                $conversion = $existing->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType']);
            } else {
                $conversion = LeaveConversion::query()->create([
                    'leave_id' => $leave->id,
                    'from_leave_type_id' => $vlType->id,
                    'to_leave_type_id' => $slType->id,
                    'med_cert_attachment' => $path,
                    'reason' => $reason,
                    'status' => ConversionStatus::PendingReview,
                ])->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType']);
            }

            $leave->update(['status' => LeaveStatus::PendingConversion]);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $employee->id,
                'activity_type' => ActivityType::ConversionRequested,
                'remarks' => $reason,
            ]);

            return $conversion->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType']);
        });

        $this->notificationService->notifyAdminOnConversionRequest($conversion);

        return $conversion;
    }

    public function approve(LeaveConversion $conversion, Employee $admin): LeaveConversion
    {
        $this->assertIsAdmin($admin);
        $this->assertConversionIsPending($conversion);

        $conversion = DB::transaction(function () use ($conversion, $admin) {
            $conversion = LeaveConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->with('leaveInfo')
                ->firstOrFail();

            $this->assertConversionIsPending($conversion);

            $leave = LeaveInfo::query()
                ->whereKey($conversion->leave_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($leave->status !== LeaveStatus::PendingConversion) {
                throw ValidationException::withMessages([
                    'leave' => ['Only a leave pending conversion can be approved.'],
                ]);
            }

            try {
                $this->balanceService->applyConversion($conversion);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'balance' => [$exception->getMessage()],
                ]);
            }

            $leave->update([
                'leave_type_id' => $conversion->to_leave_type_id,
                'status' => LeaveStatus::ConvertedToSl,
            ]);

            $conversion->update([
                'status' => ConversionStatus::Approved,
                'reviewed_by_emp_id' => $admin->id,
                'reviewed_at' => now(),
                'remarks' => null,
            ]);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $admin->id,
                'activity_type' => ActivityType::ConversionApproved,
            ]);

            return $conversion->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType']);
        });

        $this->notificationService->notifyEmployeeOnConversionApproved($conversion, $admin);

        return $conversion;
    }

    public function reject(LeaveConversion $conversion, Employee $admin, string $remarks): LeaveConversion
    {
        $this->assertIsAdmin($admin);
        $this->assertConversionIsPending($conversion);

        $conversion = DB::transaction(function () use ($conversion, $admin, $remarks) {
            $conversion = LeaveConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->with('leaveInfo')
                ->firstOrFail();

            $this->assertConversionIsPending($conversion);

            $leave = LeaveInfo::query()
                ->whereKey($conversion->leave_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($leave->status !== LeaveStatus::PendingConversion) {
                throw ValidationException::withMessages([
                    'leave' => ['Only a leave pending conversion can be rejected.'],
                ]);
            }

            $leave->update(['status' => LeaveStatus::Confirmed]);

            $conversion->update([
                'status' => ConversionStatus::Rejected,
                'reviewed_by_emp_id' => $admin->id,
                'reviewed_at' => now(),
                'remarks' => $remarks,
            ]);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $admin->id,
                'activity_type' => ActivityType::ConversionRejected,
                'remarks' => $remarks,
            ]);

            return $conversion->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType']);
        });

        $this->notificationService->notifyEmployeeOnConversionRejected($conversion, $admin, $remarks);

        return $conversion;
    }

    public function canAccessMedCert(LeaveConversion $conversion, Employee $viewer): bool
    {
        if ($conversion->med_cert_attachment === null) {
            return false;
        }

        $leave = $conversion->leaveInfo;

        if ($leave === null) {
            return false;
        }

        // Same viewers as leave detail: employee, PIC-group approvers, past/current approvers, admin.
        return $this->listService->canView($leave, $viewer);
    }

    private function assertEmployeeOwnsLeave(LeaveInfo $leave, Employee $employee): void
    {
        if ((int) $leave->emp_id !== (int) $employee->id) {
            throw new AuthorizationException('You can only request conversion for your own leave.');
        }
    }

    private function assertLeaveIsConvertible(LeaveInfo $leave): void
    {
        if ($leave->status !== LeaveStatus::Confirmed) {
            throw ValidationException::withMessages([
                'leave' => ['Only a confirmed Vacation Leave can be converted to Sick Leave.'],
            ]);
        }
    }

    private function assertIsAdmin(Employee $employee): void
    {
        if (! $this->approverResolver->contextFor($employee->id)->isAdmin) {
            throw new AuthorizationException('Only admin can review leave conversions.');
        }
    }

    private function assertConversionIsPending(LeaveConversion $conversion): void
    {
        if ($conversion->status !== ConversionStatus::PendingReview) {
            throw ValidationException::withMessages([
                'conversion' => ['This conversion request is no longer pending review.'],
            ]);
        }
    }
}
