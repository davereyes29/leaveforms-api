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
        private readonly LeaveApprovalPolicy $approvalPolicy,
    ) {}

    /**
     * VL-style designated managers for the person on leave (live formspic).
     *
     * Used to CC PICs on SL submit / cancel when the snapshotted requirement is admin only.
     *
     * @return Collection<int, Employee>
     */
    public function designatedManagersForEmployee(int $empId): Collection
    {
        $collected = collect();

        foreach ($this->approvalPolicy->vlRequirementsForFiler($empId) as $row) {
            $type = $row['required_approver_type'] ?? null;

            if ($type === RequiredApproverType::President) {
                $collected = $collected->concat($this->presidentEmployee());

                continue;
            }

            if ($type === RequiredApproverType::Admin) {
                continue;
            }

            $collected = $collected->concat($this->managersForLevels($empId, $row['allowed_levels'] ?? []));
        }

        return $collected->unique('id')->values();
    }

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
     * Approvers who can still act on the first unsatisfied requirement.
     *
     * @return Collection<int, Employee>
     */
    public function forOpenRequirement(LeaveInfo $leave): Collection
    {
        $leave->loadMissing('approvalRequirements');

        $requirement = $leave->approvalRequirements
            ->sortBy('display_order')
            ->first(fn (LeaveApprovalRequirement $requirement) => ! $requirement->is_satisfied);

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

        return $this->managersForLevels($leave->emp_id, $levels);
    }

    /**
     * @param  list<int>  $levels
     * @return Collection<int, Employee>
     */
    private function managersForLevels(int $empId, array $levels): Collection
    {
        if ($levels === []) {
            return collect();
        }

        $filerGroup = $this->approverResolver->filerGroupAbbreviation($empId);

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
