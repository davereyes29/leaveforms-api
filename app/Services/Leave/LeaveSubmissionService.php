<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\SpecialLeaveAssignmentType;
use App\Enums\Leave\SpecialLeaveReason;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates filing a new leave request (VL, SL, PTB, EL, LL, ML, PL, or SPL).
 *
 * VL and ML do not accept attachments. SL and PTB require a medical certificate.
 * EL, LL, and SPL require a supporting document. PL requires a Paternity Notification Form.
 * LL is always unpaid. ML/PL credits are granted on submit. SPL is paid without credits.
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
        private readonly ParentalLeavePolicy $parentalLeavePolicy,
        private readonly SpecialLeavePolicy $specialLeavePolicy,
        private readonly VacationLeaveNoticePolicy $vacationLeaveNoticePolicy,
    ) {}

    /**
     * @param  array{
     *     emp_id?: ?int,
     *     code?: string,
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string,
     *     assignment_type?: string,
     *     special_leave_reason?: string,
     *     leave_attachment?: UploadedFile|list<UploadedFile>
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
        $attachments = LeaveAttachmentService::uploadedFiles($data['leave_attachment'] ?? null);
        $assignmentType = $this->assignmentTypeFrom($data);
        $specialLeaveReason = $this->specialLeaveReasonFrom($data);
        $specialLeave = null;

        if ($code === 'SPL') {
            if ($assignmentType === null || $specialLeaveReason === null) {
                throw ValidationException::withMessages([
                    'special_leave_reason' => ['A qualifying event is required to file Special Leave.'],
                ]);
            }

            $specialLeave = $this->specialLeavePolicy->allotment($assignmentType, $specialLeaveReason);
        }

        $duration = $this->durationService->calculate(
            $data['start_date'],
            $data['end_date'],
            $dayType,
            $code,
            $specialLeave,
        );

        $this->overlapValidator->assertNoOverlap(
            $employeeOnLeave->id,
            $data['start_date'],
            $data['end_date'],
        );

        $this->vacationLeaveNoticePolicy->assertAdvanceNotice(
            $code,
            $duration,
            $data['start_date'],
            (int) $filer->id !== (int) $employeeOnLeave->id,
        );

        if (LeaveTypeRules::isUnpaid($code)) {
            $this->longLeavePolicy->assertEligibleToFile((int) $employeeOnLeave->id);
            $paidDays = '0.00';
            $lwopDays = $duration;
        } elseif (LeaveTypeRules::grantsCreditsOnSubmit($code)) {
            $this->parentalLeavePolicy->assertEligibleToFile($employeeOnLeave, $code);
            $paidDays = $duration;
            $lwopDays = '0.00';
        } elseif (! LeaveTypeRules::usesCredits($code)) {
            $paidDays = $duration;
            $lwopDays = '0.00';
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
            $attachments,
            $assignmentType,
            $specialLeaveReason,
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
                'assignment_type' => $assignmentType,
                'special_leave_reason' => $specialLeaveReason,
                'status' => LeaveStatus::PendingApproval,
            ]);

            if ($attachments !== []) {
                $this->attachmentService->storeManyForLeave($leave, $attachments);
            }

            foreach ($this->approvalPolicy->requirementsFor($employeeOnLeave->id, $code) as $requirement) {
                LeaveApprovalRequirement::query()->create([
                    'leave_id' => $leave->id,
                    'display_order' => $requirement['display_order'],
                    'allowed_levels' => $requirement['allowed_levels'],
                    'required_approver_type' => $requirement['required_approver_type'],
                ]);
            }

            $fresh = $leave->fresh();

            if (LeaveTypeRules::grantsCreditsOnSubmit($code)) {
                $this->balanceService->grantParentalEntitlement($fresh);
            }

            if (LeaveTypeRules::usesCredits($code)) {
                $this->balanceService->reservePaidDays($fresh);
            }

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $filer->id,
                'activity_type' => ActivityType::Submitted,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        $this->notificationService->notifyApproversOnSubmit($leave);

        return $leave;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assignmentTypeFrom(array $data): ?SpecialLeaveAssignmentType
    {
        $value = $data['assignment_type'] ?? null;

        if ($value instanceof SpecialLeaveAssignmentType) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return SpecialLeaveAssignmentType::from($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function specialLeaveReasonFrom(array $data): ?SpecialLeaveReason
    {
        $value = $data['special_leave_reason'] ?? null;

        if ($value instanceof SpecialLeaveReason) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return SpecialLeaveReason::from($value);
    }
}
