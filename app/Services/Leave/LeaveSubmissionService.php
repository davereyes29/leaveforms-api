<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates filing a new leave request (VL, SL, PTB, EL, or LL).
 *
 * VL and LL do not accept attachments. SL and PTB require a medical certificate.
 * EL requires a supporting document. LL is always unpaid.
 */
class LeaveSubmissionService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly LeaveApprovalPolicy $approvalPolicy,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOverlapValidator $overlapValidator,
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly LeaveNotificationService $notificationService,
        private readonly LongLeavePolicy $longLeavePolicy,
    ) {}

    /**
     * @param  array{
     *     emp_id?: ?int,
     *     code?: string,
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string,
     *     leave_attachment?: UploadedFile
     * }  $data
     */
    public function submit(Employee $filer, array $data): LeaveInfo
    {
        $employeeOnLeave = $this->onBehalfAuthorization->resolveEmployeeOnLeave(
            $filer,
            isset($data['emp_id']) ? (int) $data['emp_id'] : null,
        );

        $code = strtoupper((string) ($data['code'] ?? 'VL'));
        $leaveType = LeaveType::query()->where('code', $code)->firstOrFail();
        $dayType = DayType::from($data['day_type']);
        $attachment = $data['leave_attachment'] ?? null;

        $duration = $this->durationService->calculate(
            $data['start_date'],
            $data['end_date'],
            $dayType,
            $code,
        );

        $this->overlapValidator->assertNoOverlap(
            $employeeOnLeave->id,
            $data['start_date'],
            $data['end_date'],
        );

        if (LeaveTypeRules::isUnpaid($code)) {
            $this->longLeavePolicy->assertEligibleToFile($employeeOnLeave, $data['start_date']);
            $paidDays = '0.00';
            $lwopDays = $duration;
        } else {
            $available = $this->balanceService->availableFor($employeeOnLeave->id, $leaveType->id);

            if (! LeaveTypeRules::allowsLwop($code) && bccomp($duration, $available, 2) > 0) {
                throw ValidationException::withMessages([
                    'start_date' => [
                        $leaveType->type.' cannot exceed remaining credits (unpaid days are not allowed). Remaining: '.$available.' day(s).',
                    ],
                ]);
            }

            $paidDays = bccomp($duration, $available, 2) <= 0 ? $duration : $available;
            $lwopDays = bcsub($duration, $paidDays, 2);
        }

        $leave = DB::transaction(function () use (
            $filer,
            $employeeOnLeave,
            $data,
            $leaveType,
            $code,
            $dayType,
            $duration,
            $paidDays,
            $lwopDays,
            $attachment,
        ) {
            $leave = LeaveInfo::query()->create([
                'emp_id' => $employeeOnLeave->id,
                'filed_by_emp_id' => $filer->id,
                'leave_type_id' => $leaveType->id,
                'duration' => $duration,
                'paid_days' => $paidDays,
                'lwop_days' => $lwopDays,
                'day_type' => $dayType,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'leave_reason' => $data['leave_reason'],
                'leave_attachment' => null,
                'status' => LeaveStatus::PendingApproval,
            ]);

            if ($attachment instanceof UploadedFile) {
                $leave->update([
                    'leave_attachment' => $this->attachmentService->store($leave, $attachment),
                ]);
            }

            foreach ($this->approvalPolicy->requirementsFor($employeeOnLeave->id, $code) as $requirement) {
                LeaveApprovalRequirement::query()->create([
                    'leave_id' => $leave->id,
                    'display_order' => $requirement['display_order'],
                    'allowed_levels' => $requirement['allowed_levels'],
                    'required_approver_type' => $requirement['required_approver_type'],
                ]);
            }

            $this->balanceService->reservePaidDays($leave->fresh());

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $filer->id,
                'activity_type' => ActivityType::Submitted,
            ]);

            return $leave->fresh([
                'leaveType',
                'approvalRequirements',
                'conversion.fromLeaveType',
                'conversion.toLeaveType',
            ]);
        });

        $this->notificationService->notifyApproversOnSubmit($leave);

        return $leave;
    }
}
