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
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates filing a new leave request (VL for now).
 *
 * VL does not accept attachments — those belong to other leave types.
 */
class LeaveSubmissionService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly LeaveApprovalPolicy $approvalPolicy,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOverlapValidator $overlapValidator,
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    /**
     * @param  array{
     *     emp_id?: ?int,
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string
     * }  $data
     */
    public function submitVl(Employee $filer, array $data): LeaveInfo
    {
        $employeeOnLeave = $this->onBehalfAuthorization->resolveEmployeeOnLeave(
            $filer,
            isset($data['emp_id']) ? (int) $data['emp_id'] : null,
        );

        $vlType = LeaveType::query()->where('code', 'VL')->firstOrFail();
        $dayType = DayType::from($data['day_type']);

        $duration = $this->durationService->calculate(
            $data['start_date'],
            $data['end_date'],
            $dayType,
        );

        $this->overlapValidator->assertNoOverlap(
            $employeeOnLeave->id,
            $data['start_date'],
            $data['end_date'],
        );

        $available = $this->balanceService->availableFor($employeeOnLeave->id, $vlType->id);
        $paidDays = bccomp($duration, $available, 2) <= 0 ? $duration : $available;
        $lwopDays = bcsub($duration, $paidDays, 2);

        $leave = DB::transaction(function () use ($filer, $employeeOnLeave, $data, $vlType, $dayType, $duration, $paidDays, $lwopDays) {
            $leave = LeaveInfo::query()->create([
                'emp_id' => $employeeOnLeave->id,
                'filed_by_emp_id' => $filer->id,
                'leave_type_id' => $vlType->id,
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

            foreach ($this->approvalPolicy->requirementsForFiler($employeeOnLeave->id) as $requirement) {
                LeaveApprovalRequirement::query()->create([
                    'leave_id' => $leave->id,
                    'display_order' => $requirement['display_order'],
                    'allowed_levels' => $requirement['allowed_levels'],
                    'required_approver_type' => $requirement['required_approver_type'],
                ]);
            }

            $this->balanceService->reservePaidDays($leave);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $filer->id,
                'activity_type' => ActivityType::Submitted,
            ]);

            return $leave->fresh([
                'leaveType',
                'approvalRequirements',
            ]);
        });

        $this->notificationService->notifyApproversOnSubmit($leave);

        return $leave;
    }
}
