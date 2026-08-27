<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an open leave request before any approver has acted.
 *
 * VL and LL do not accept attachments. SL, PTB, and EL may replace the supporting file.
 */
class LeaveModificationService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOverlapValidator $overlapValidator,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly LongLeavePolicy $longLeavePolicy,
    ) {}

    /**
     * @param  array{
     *     start_date: string,
     *     end_date: string,
     *     day_type: string,
     *     leave_reason: string,
     *     leave_attachment?: UploadedFile
     * }  $data
     */
    public function update(LeaveInfo $leave, Employee $actor, array $data): LeaveInfo
    {
        $this->assertActorCanModify($leave, $actor);
        $this->assertLeaveIsEditable($leave);
        $this->assertIsEditableType($leave);

        $dayType = DayType::from($data['day_type']);
        $code = strtoupper((string) ($leave->leaveType?->code ?? 'VL'));
        $attachment = $data['leave_attachment'] ?? null;

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

        if (LeaveTypeRules::isUnpaid($code)) {
            $employeeOnLeave = Employee::query()->findOrFail($leave->emp_id);
            $this->longLeavePolicy->assertEligibleToFile(
                $employeeOnLeave,
                $data['start_date'],
                $leave->id,
            );
            $paidDays = '0.00';
            $lwopDays = $duration;
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

        return DB::transaction(function () use (
            $leave,
            $actor,
            $data,
            $dayType,
            $code,
            $duration,
            $paidDays,
            $lwopDays,
            $attachment,
        ) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanModify($leave, $actor);
            $this->assertLeaveIsEditable($leave);

            $this->balanceService->releaseReservation($leave, 'Leave modified');

            $payload = [
                'duration' => $duration,
                'paid_days' => $paidDays,
                'lwop_days' => $lwopDays,
                'day_type' => $dayType,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'leave_reason' => $data['leave_reason'],
            ];

            if (! LeaveTypeRules::requiresAttachment($code)) {
                $payload['leave_attachment'] = null;
            }

            $leave->update($payload);

            if (LeaveTypeRules::requiresAttachment($code) && $attachment instanceof UploadedFile) {
                $this->attachmentService->replace($leave, $attachment);
            }

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

    private function assertIsEditableType(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');

        $code = strtoupper((string) ($leave->leaveType?->code ?? ''));

        if (! LeaveTypeRules::isEditable($code)) {
            throw ValidationException::withMessages([
                'leave' => ['Only vacation, sick, PTB, emergency, and long leave requests can be edited through this endpoint for now.'],
            ]);
        }
    }
}
