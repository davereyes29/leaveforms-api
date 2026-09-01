<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an open leave request before any approver has acted.
 *
 * VL and ML do not accept attachments. SL, PTB, EL, LL, and PL files are
 * added or removed through dedicated attachment endpoints.
 */
class LeaveModificationService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOverlapValidator $overlapValidator,
        private readonly LeaveNotificationService $notificationService,
        private readonly LongLeavePolicy $longLeavePolicy,
        private readonly ParentalLeavePolicy $parentalLeavePolicy,
        private readonly VacationLeaveNoticePolicy $vacationLeaveNoticePolicy,
    ) {}

    /**
     * @param  array{
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string
     * }  $data
     */
    public function update(LeaveInfo $leave, Employee $actor, array $data): LeaveInfo
    {
        $this->assertActorCanModify($leave, $actor);
        $this->assertLeaveIsEditable($leave);
        $this->assertIsEditableType($leave);

        $dayType = DayType::from($data['day_type']);
        $code = strtoupper((string) ($leave->leaveType?->code ?? 'VL'));

        $duration = $this->durationService->calculate(
            $data['start_date'],
            $data['end_date'],
            $dayType,
            $code,
        );

        $this->overlapValidator->assertNoOverlap(
            $leave->emp_id,
            $data['start_date'],
            $data['end_date'],
            $leave->id,
        );

        $this->vacationLeaveNoticePolicy->assertAdvanceNotice(
            $code,
            $duration,
            $data['start_date'],
            (int) $leave->filed_by_emp_id !== (int) $leave->emp_id,
        );

        if (LeaveTypeRules::isUnpaid($code)) {
            $this->longLeavePolicy->assertEligibleToFile((int) $leave->emp_id, $leave->id);
            $paidDays = '0.00';
            $lwopDays = $duration;
        } elseif (LeaveTypeRules::grantsCreditsOnSubmit($code)) {
            $employeeOnLeave = Employee::query()->findOrFail($leave->emp_id);
            $this->parentalLeavePolicy->assertEligibleToFile($employeeOnLeave, $code, $leave->id);
            $paidDays = $duration;
            $lwopDays = '0.00';
        } else {
            $effectiveAvailable = $this->balanceService->availableForEdit($leave);

            if (! LeaveTypeRules::allowsLwop($code) && bccomp($duration, $effectiveAvailable, 2) > 0) {
                throw ValidationException::withMessages([
                    'start_date' => [
                        ($leave->leaveType?->type ?? 'This leave').' cannot exceed remaining credits (unpaid days are not allowed). Remaining: '.$effectiveAvailable.' day(s).',
                    ],
                ]);
            }

            $paidDays = bccomp($duration, $effectiveAvailable, 2) <= 0 ? $duration : $effectiveAvailable;
            $lwopDays = bcsub($duration, $paidDays, 2);
        }

        $leave = DB::transaction(function () use (
            $leave,
            $actor,
            $data,
            $dayType,
            $duration,
            $paidDays,
            $lwopDays,
        ) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanModify($leave, $actor);
            $this->assertLeaveIsEditable($leave);

            $this->balanceService->releaseReservation($leave, 'Leave modified');

            $leave->update([
                'duration' => $duration,
                'paid_days' => $paidDays,
                'lwop_days' => $lwopDays,
                'day_type' => $dayType,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'leave_reason' => $data['leave_reason'],
            ]);

            $this->balanceService->reservePaidDays($leave->fresh());

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::Modified,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        $this->notificationService->notifyApproversOnEdit($leave);

        return $leave;
    }

    private function assertActorCanModify(LeaveInfo $leave, Employee $actor): void
    {
        if (! LeaveActorAuthorization::mayModify($leave, $actor)) {
            $isOnBehalf = (int) $leave->filed_by_emp_id !== (int) $leave->emp_id;

            throw new AuthorizationException(
                $isOnBehalf
                    ? 'Only the person who filed this leave can modify it.'
                    : 'You are not allowed to modify this leave request.',
            );
        }
    }

    private function assertLeaveIsEditable(LeaveInfo $leave): void
    {
        if ($leave->status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => [
                    'Leave can only be edited while pending approval with no approvals yet. Cancel and submit a new request instead.',
                ],
            ]);
        }

        if ($leave->approvalRequirements()->where('is_satisfied', true)->exists()) {
            throw ValidationException::withMessages([
                'leave' => [
                    'Leave can no longer be edited because an approver has already acted. Cancel and submit a new request instead.',
                ],
            ]);
        }
    }

    private function assertIsEditableType(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');

        $code = strtoupper((string) ($leave->leaveType?->code ?? ''));

        if (! LeaveTypeRules::isEditable($code)) {
            throw ValidationException::withMessages([
                'leave' => ['Only vacation, sick, PTB, emergency, long, maternity, and paternity leave requests can be edited through this endpoint for now.'],
            ]);
        }
    }
}
