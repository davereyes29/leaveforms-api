<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\ApproverType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveApprovalHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveFinalizationService $finalizationService,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveNotificationService $notificationService,
        private readonly VacationLeaveQuarterNoticeService $quarterNoticeService,
        private readonly DailyReportLeaveSyncService $dailyReportSync,
    ) {}

    public function approve(LeaveInfo $leave, Employee $approver): LeaveInfo
    {
        $this->assertLeaveIsApprovable($leave);

        $leave = DB::transaction(function () use ($leave, $approver) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLeaveIsApprovable($leave);

            $requirement = $this->resolveActionableRequirement($leave, $approver);
            $context = $this->approverResolver->contextFor($approver->id);

            LeaveApprovalHistory::query()->create([
                'leave_id' => $leave->id,
                'requirement_id' => $requirement->id,
                'approver_emp_id' => $approver->id,
                'approval_level' => $this->historyApprovalLevel($requirement, $context),
                'approver_type' => $this->historyApproverType($requirement, $context),
                'is_approved' => true,
            ]);

            $requirement->update([
                'is_satisfied' => true,
                'satisfied_at' => now(),
            ]);

            if ($this->allRequirementsSatisfied($leave)) {
                $this->finalizationService->finalize($leave, $approver);
            }

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        if ($leave->status === LeaveStatus::Confirmed) {
            $this->notificationService->notifyEmployeeOnConfirm($leave, $approver);
            $this->quarterNoticeService->onConfirmed($leave);
            $this->dailyReportSync->sync($leave);
        }

        return $leave;
    }

    public function reject(LeaveInfo $leave, Employee $approver, string $remarks): LeaveInfo
    {
        $this->assertLeaveIsApprovable($leave);

        $leave = DB::transaction(function () use ($leave, $approver, $remarks) {
            $leave = LeaveInfo::query()
                ->whereKey($leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLeaveIsApprovable($leave);

            $requirement = $this->resolveActionableRequirement($leave, $approver);
            $context = $this->approverResolver->contextFor($approver->id);

            LeaveApprovalHistory::query()->create([
                'leave_id' => $leave->id,
                'requirement_id' => $requirement->id,
                'approver_emp_id' => $approver->id,
                'approval_level' => $this->historyApprovalLevel($requirement, $context),
                'approver_type' => $this->historyApproverType($requirement, $context),
                'is_approved' => false,
                'remarks' => $remarks,
            ]);

            $leave->update(['status' => LeaveStatus::Rejected]);

            $leave->loadMissing('leaveType');

            if (LeaveTypeRules::usesCredits((string) $leave->leaveType?->code)) {
                $this->balanceService->releaseReservation($leave, $remarks);
            }

            if (LeaveTypeRules::grantsCreditsOnSubmit((string) $leave->leaveType?->code)) {
                $this->balanceService->reverseParentalEntitlement($leave, $remarks);
            }

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $approver->id,
                'activity_type' => ActivityType::Rejected,
                'remarks' => $remarks,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        $this->notificationService->notifyEmployeeOnReject($leave, $approver, $remarks);

        return $leave;
    }

    private function assertLeaveIsApprovable(LeaveInfo $leave): void
    {
        if ($leave->status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => ['This leave request is no longer open for approval.'],
            ]);
        }
    }

    private function resolveActionableRequirement(LeaveInfo $leave, Employee $approver): LeaveApprovalRequirement
    {
        $context = $this->approverResolver->contextFor($approver->id);
        $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

        $requirements = $leave->approvalRequirements()
            ->with('history')
            ->orderBy('display_order')
            ->get();

        foreach ($requirements as $requirement) {
            if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                return $requirement;
            }
        }

        throw new AuthorizationException('You are not allowed to act on this leave request.');
    }

    private function allRequirementsSatisfied(LeaveInfo $leave): bool
    {
        return ! $leave->approvalRequirements()
            ->where('is_satisfied', false)
            ->exists();
    }

    private function historyApprovalLevel(
        LeaveApprovalRequirement $requirement,
        ApproverContext $context,
    ): ?int {
        if ($requirement->required_approver_type !== null) {
            return null;
        }

        return $context->approvalLevel;
    }

    private function historyApproverType(
        LeaveApprovalRequirement $requirement,
        ApproverContext $context,
    ): ApproverType {
        if ($requirement->required_approver_type === RequiredApproverType::President) {
            return ApproverType::President;
        }

        if ($requirement->required_approver_type === RequiredApproverType::Admin) {
            return ApproverType::Admin;
        }

        return $context->approverType() ?? ApproverType::Leader;
    }
}
