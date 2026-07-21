<?php

namespace App\Services\Leave;

use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Formspic;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use Illuminate\Support\Collection;

/**
 * Finds approver employees who should receive email for a leave requirement.
 */
class LeaveApproverRecipientService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * Approvers who can act on the first pending requirement (lowest display_order).
     *
     * @return Collection<int, Employee>
     */
    public function forFirstPendingRequirement(LeaveInfo $leave): Collection
    {
        $leave->loadMissing('approvalRequirements');

        $requirement = $leave->approvalRequirements
            ->sortBy('display_order')
            ->first();

        if ($requirement === null) {
            return collect();
        }

        return $this->forRequirement($leave, $requirement);
    }

    /**
     * @return Collection<int, Employee>
     */
    public function forRequirement(LeaveInfo $leave, LeaveApprovalRequirement $requirement): Collection
    {
        if ($requirement->required_approver_type === RequiredApproverType::President) {
            return $this->presidentEmployee();
        }

        if ($requirement->required_approver_type === RequiredApproverType::Admin) {
            return collect();
        }

        $levels = $requirement->allowed_levels ?? [];

        if ($levels === []) {
            return collect();
        }

        $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

        if ($filerGroup === null) {
            return collect();
        }

        $matchingIds = Formspic::query()
            ->whereIn('fldRole', $levels)
            ->get()
            ->filter(function (Formspic $row) use ($filerGroup) {
                return in_array($filerGroup, $row->groupsArray(), true);
            })
            ->pluck('fldEmployeeNum')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return Employee::query()
            ->whereIn('id', $matchingIds)
            ->where('emp_status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function presidentEmployee(): Collection
    {
        $presidentId = (int) config('leave.president_emp_id');

        if ($presidentId <= 0) {
            return collect();
        }

        $president = Employee::query()
            ->whereKey($presidentId)
            ->where('emp_status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        return $president !== null ? collect([$president]) : collect();
    }
}
