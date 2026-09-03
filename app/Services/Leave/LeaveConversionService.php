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
 * VL → SL or VL → EL conversion after the employee returns.
 */
class LeaveConversionService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function request(
        LeaveInfo $leave,
        Employee $employee,
        array $files,
        string $reason,
        string $toCode = 'SL',
    ): LeaveConversion {
        $this->assertEmployeeOwnsLeave($leave, $employee);
        $this->assertLeaveIsConvertible($leave);

        $toCode = strtoupper($toCode);
        $field = LeaveTypeRules::conversionAttachmentField($toCode);
        $files = LeaveAttachmentService::uploadedFiles($files);

        if (! LeaveTypeRules::isConversionTarget($toCode)) {
            throw ValidationException::withMessages([
                'to_code' => ['Conversion is only allowed to Sick Leave or Emergency Leave.'],
            ]);
        }

        if ($files === []) {
            $label = LeaveTypeRules::attachmentLabel($toCode)['singular'] ?? 'attachment';

            throw ValidationException::withMessages([
                $field => ['A '.$label.' is required to request VL → '.$toCode.' conversion.'],
            ]);
        }

        $maxFiles = (int) config('leave.attachments.max_files', 5);

        if (count($files) > $maxFiles) {
            throw ValidationException::withMessages([
                $field => ["You can attach at most {$maxFiles} files."],
            ]);
        }

        $conversion = DB::transaction(function () use ($leave, $employee, $files, $reason, $toCode) {
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
                    'leave' => ['This leave has already been converted.'],
                ]);
            }

            $vlType = LeaveType::query()->where('code', 'VL')->firstOrFail();
            $toType = LeaveType::query()->where('code', $toCode)->firstOrFail();

            if ((int) $leave->leave_type_id !== (int) $vlType->id) {
                throw ValidationException::withMessages([
                    'leave' => ['Only Vacation Leave can be converted.'],
                ]);
            }

            if ($toCode === 'EL') {
                $this->assertEnoughElCredits($leave, $toType);
            }

            $conversionRelations = ['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType', 'attachments'];

            if ($existing !== null) {
                $existing->update([
                    'from_leave_type_id' => $vlType->id,
                    'to_leave_type_id' => $toType->id,
                    'reason' => $reason,
                    'status' => ConversionStatus::PendingReview,
                    'reviewed_by_emp_id' => null,
                    'remarks' => null,
                    'reviewed_at' => null,
                ]);

                $conversion = $existing->fresh($conversionRelations);
                $this->attachmentService->replaceConversionAttachments($conversion, $files);
            } else {
                $conversion = LeaveConversion::query()->create([
                    'leave_id' => $leave->id,
                    'from_leave_type_id' => $vlType->id,
                    'to_leave_type_id' => $toType->id,
                    'reason' => $reason,
                    'status' => ConversionStatus::PendingReview,
                ])->fresh($conversionRelations);

                $this->attachmentService->storeManyForConversion($conversion, $files);
            }

            $leave->update(['status' => LeaveStatus::PendingConversion]);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $employee->id,
                'activity_type' => ActivityType::ConversionRequested,
                'remarks' => $reason,
            ]);

            return $conversion->fresh($conversionRelations);
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
                ->with(['leaveInfo', 'toLeaveType'])
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

            $toCode = strtoupper((string) ($conversion->toLeaveType?->code ?? 'SL'));
            $leaveUpdate = [
                'leave_type_id' => $conversion->to_leave_type_id,
                'status' => $toCode === 'EL'
                    ? LeaveStatus::ConvertedToEl
                    : LeaveStatus::ConvertedToSl,
            ];

            if ($toCode === 'EL') {
                $leaveUpdate['paid_days'] = $leave->duration;
                $leaveUpdate['lwop_days'] = 0;
            }

            $leave->update($leaveUpdate);

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

            return $conversion->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType', 'attachments']);
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

            return $conversion->fresh(['fromLeaveType', 'toLeaveType', 'leaveInfo.leaveType', 'attachments']);
        });

        $this->notificationService->notifyEmployeeOnConversionRejected($conversion, $admin, $remarks);

        return $conversion;
    }

    private function assertEnoughElCredits(LeaveInfo $leave, LeaveType $elType): void
    {
        $available = $this->balanceService->availableFor((int) $leave->emp_id, (int) $elType->id);
        $duration = bcadd((string) $leave->duration, '0', 2);

        if (bccomp($available, $duration, 2) < 0) {
            throw ValidationException::withMessages([
                'el' => [
                    "Remaining Emergency Leave credits ({$available}) are less than this leave duration ({$duration}).",
                ],
            ]);
        }
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
                'leave' => ['Only a confirmed Vacation Leave can be converted.'],
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
