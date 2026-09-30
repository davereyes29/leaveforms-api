<?php

namespace App\Services\Leave;

use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Formspic;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveApprovalRequirement;

/**
 * Determines whether an employee can act on an approval requirement.
 */
class LeaveApproverResolver
{
    /** @var array<int, Group|null> */
    private array $groupByIdCache = [];

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
            isAdmin: (int) $approverEmpId === (int) config('leave.admin_emp_id'),
        );
    }

    /**
     * Employees with formspic.fldRole = 4 (Senior Manager).
     *
     * Used for president File Leave for Members, All Leave Requests, and
     * Team Leave Calendar. Does not write kdtphdb.
     *
     * @return list<int>
     */
    public function p4EmployeeIds(?int $excludeEmpId = null, bool $activeOnly = false): array
    {
        $ids = Formspic::query()
            ->where('fldRole', 4)
            ->pluck('fldEmployeeNum')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $query = Employee::query()->whereIn('id', $ids);

        if ($excludeEmpId !== null) {
            $query->whereKeyNot($excludeEmpId);
        }

        if ($activeOnly) {
            $query->where('emp_status', 1);
        }

        return $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * formspic.fldRole per employee (null when there is no PIC row).
     *
     * @param  list<int>  $empIds
     * @return array<int, int|null>
     */
    public function approvalLevelsFor(array $empIds): array
    {
        if ($empIds === []) {
            return [];
        }

        $rows = Formspic::query()
            ->whereIn('fldEmployeeNum', $empIds)
            ->get()
            ->unique('fldEmployeeNum')
            ->keyBy(fn (Formspic $row): int => (int) $row->fldEmployeeNum);

        $levels = [];

        foreach ($empIds as $empId) {
            $row = $rows->get($empId);
            $levels[$empId] = $row ? (int) $row->fldRole : null;
        }

        return $levels;
    }

    /**
     * Primary group for leaveforms: config override, else employee_list.group_id.
     *
     * @return array<int, int> emp_id => group_list.id
     */
    public function groupOverrides(): array
    {
        $map = [];

        foreach (config('leave.employee_group_overrides', []) as $empId => $groupId) {
            $map[(int) $empId] = (int) $groupId;
        }

        return $map;
    }

    public function overriddenGroupId(int $empId): ?int
    {
        $overrides = $this->groupOverrides();

        return $overrides[$empId] ?? null;
    }

    /**
     * Swap the in-memory group to the leaveforms override (does not write kdtphdb).
     */
    public function applyEffectiveGroup(Employee $employee): Employee
    {
        $overrideId = $this->overriddenGroupId((int) $employee->id);

        if ($overrideId === null) {
            return $employee;
        }

        $group = $this->groupById($overrideId);
        $employee->setAttribute('group_id', $overrideId);
        $employee->setRelation('group', $group);

        return $employee;
    }

    public function filerGroupAbbreviation(int $empOnLeaveId): ?string
    {
        $overrideId = $this->overriddenGroupId($empOnLeaveId);

        if ($overrideId !== null) {
            return $this->groupById($overrideId)?->abbreviation;
        }

        return Employee::query()
            ->with('group')
            ->find($empOnLeaveId)
            ?->group
            ?->abbreviation;
    }

    /**
     * Employees whose effective primary group is in $groupIds.
     *
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    public function employeeIdsInEffectiveGroups(
        array $groupIds,
        ?int $excludeEmpId = null,
        bool $activeOnly = false,
    ): array {
        if ($groupIds === []) {
            return [];
        }

        $groupIds = array_map('intval', $groupIds);
        $includeIds = [];
        $excludeIds = [];

        foreach ($this->groupOverrides() as $empId => $groupId) {
            if (in_array($groupId, $groupIds, true)) {
                $includeIds[] = $empId;
            } else {
                $excludeIds[] = $empId;
            }
        }

        $query = Employee::query()->where(function ($q) use ($groupIds, $includeIds): void {
            $q->whereIn('group_id', $groupIds);

            if ($includeIds !== []) {
                $q->orWhereIn('id', $includeIds);
            }
        });

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($excludeEmpId !== null) {
            $query->whereKeyNot($excludeEmpId);
        }

        if ($activeOnly) {
            $query->where('emp_status', 1);
        }

        return $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function groupById(int $groupId): ?Group
    {
        if (! array_key_exists($groupId, $this->groupByIdCache)) {
            $this->groupByIdCache[$groupId] = Group::query()
                ->with('department')
                ->find($groupId);
        }

        return $this->groupByIdCache[$groupId];
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
