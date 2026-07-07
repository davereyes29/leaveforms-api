<?php

namespace App\Services\Leave;

use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Formspic;
use App\Models\Leave\LeaveApprovalRequirement;

/**
 * Determines whether an employee can act on an approval requirement.
 */
class LeaveApproverResolver
{
    public function contextFor(int $approverEmpId): ApproverContext
    {
        $formspic = Formspic::query()
            ->where('fldEmployeeNum', $approverEmpId)
            ->first();

        return new ApproverContext(
            empId: $approverEmpId,
            approvalLevel: $formspic ? (int) $formspic->fldRole : null,
            groupCodes: $formspic?->groupsArray() ?? [],
            isPresident: (int) $approverEmpId === (int) config('leave.president_emp_id'),
        );
    }

    public function filerGroupAbbreviation(int $empOnLeaveId): ?string
    {
        return Employee::query()
            ->with('group')
            ->find($empOnLeaveId)
            ?->group
            ?->abbreviation;
    }

    public function canActOnRequirement(
        ApproverContext $approver,
        LeaveApprovalRequirement $requirement,
        ?string $filerGroupAbbreviation,
    ): bool {
        if ($requirement->is_satisfied || $requirement->history()->exists()) {
            return false;
        }

        if ($requirement->required_approver_type === RequiredApproverType::President) {
            return $approver->isPresident;
        }

        if ($requirement->required_approver_type === RequiredApproverType::Admin) {
            return $approver->isAdmin;
        }

        if ($approver->approvalLevel === null) {
            return false;
        }

        if ($filerGroupAbbreviation === null) {
            return false;
        }

        if (! in_array($filerGroupAbbreviation, $approver->groupCodes, true)) {
            return false;
        }

        return in_array($approver->approvalLevel, $requirement->allowed_levels ?? [], true);
    }
}
