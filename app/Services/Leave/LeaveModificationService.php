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
 * VL does not accept attachments — those belong to other leave types.
 */
class LeaveModificationService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOverlapValidator $overlapValidator,
    ) {}

    /**
     * @param  array{
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string
     * }  $data
     */
    public function updateVl(LeaveInfo $leave, Employee $actor, array $data): LeaveInfo
    {
        $this->assertActorCanModify($leave, $actor);
        $this->assertLeaveIsEditable($leave);
        $this->assertIsVl($leave);

        $dayType = DayType::from($data['day_type']);

        $duration = $this->durationService->calculate(
            $data['start_date'],
            $data['end_date'],
            $dayType,
        );

        $this->overlapValidator->assertNoOverlap(
            $leave->emp_id,
            $data['start_date'],
            $data['end_date'],
            $leave->id,
        );

        $effectiveAvailable = $this->balanceService->availableForEdit($leave);
        $paidDays = bccomp($duration, $effectiveAvailable, 2) <= 0 ? $duration : $effectiveAvailable;
        $lwopDays = bcsub($duration, $paidDays, 2);

        return DB::transaction(function () use ($leave, $actor, $data, $dayType, $duration, $paidDays, $lwopDays) {
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
                'leave_attachment' => null,
            ]);

            $this->balanceService->reservePaidDays($leave->fresh());

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::Modified,
            ]);

            return $leave->fresh([
                'leaveType',
                'approvalRequirements.history',
                'conversion.fromLeaveType',
                'conversion.toLeaveType',
            ]);
        });
    }

    private function assertActorCanModify(LeaveInfo $leave, Employee $actor): void
    {
        $isEmployeeOnLeave = (int) $leave->emp_id === (int) $actor->id;
        $isFiler = (int) $leave->filed_by_emp_id === (int) $actor->id;

        if (! $isEmployeeOnLeave && ! $isFiler) {
            throw new AuthorizationException('You are not allowed to modify this leave request.');
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

    private function assertIsVl(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');

        if ($leave->leaveType?->code !== 'VL') {
            throw ValidationException::withMessages([
                'leave' => ['Only vacation leave requests can be edited through this endpoint for now.'],
            ]);
        }
    }
}
