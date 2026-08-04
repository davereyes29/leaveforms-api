<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use Illuminate\Support\Collection;

/**
 * Lists leave requests awaiting action from the current approver.
 */
class PendingApprovalService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return Collection<int, LeaveInfo>
     */
    public function forApprover(Employee $approver): Collection
    {
        $context = $this->approverResolver->contextFor($approver->id);

        $leaves = LeaveInfo::query()
            ->with([
                'leaveType',
                'approvalRequirements.history',
                'conversion.fromLeaveType',
                'conversion.toLeaveType',
            ])
            ->where('status', LeaveStatus::PendingApproval)
            ->orderByDesc('created_at')
            ->get();

        return $leaves->filter(function (LeaveInfo $leave) use ($approver, $context) {
            $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

            foreach ($leave->approvalRequirements as $requirement) {
                if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
