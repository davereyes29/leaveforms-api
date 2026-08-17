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
                'approvalRequirements',
            ])
            ->where('status', LeaveStatus::PendingApproval)
            ->orderByDesc('created_at')
            ->get();

        $pending = $leaves->filter(function (LeaveInfo $leave) use ($context) {
            $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

            foreach ($leave->approvalRequirements as $requirement) {
                if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                    return true;
                }
            }

            return false;
        })->values();

        $this->attachEmployees($pending);

        return $pending;
    }

    /**
     * @param  Collection<int, LeaveInfo>  $leaves
     */
    private function attachEmployees(Collection $leaves): void
    {
        if ($leaves->isEmpty()) {
            return;
        }

        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $leaves->pluck('emp_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        foreach ($leaves as $leave) {
            $leave->setAttribute('employee_on_leave', $employees->get($leave->emp_id));
        }
    }
}
