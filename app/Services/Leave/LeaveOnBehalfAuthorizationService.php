<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Authorizes filing leave on behalf of another employee.
 *
 * Who may file for whom (PIC roles from formspic.fldRole):
 *
 * | Person on leave | Who may file   |
 * |-----------------|----------------|
 * | Regular (not 1–4) | P1, P2       |
 * | P1              | P2, P3, P4     |
 * | P2              | P3, P4         |
 * | P3              | P4             |
 * | P4              | (deferred — President later) |
 *
 * Always required: target is active, and the target's effective primary group
 * (employee_list.group_id, or LEAVE_EMPLOYEE_GROUP_OVERRIDES) is in the filer's
 * formspic.fldGroups.
 */
class LeaveOnBehalfAuthorizationService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * Resolve the target employee (self or on-behalf). Defaults to the logged-in employee.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function resolveEmployeeOnLeave(Employee $filer, ?int $empId): Employee
    {
        if ($empId === null || $empId === $filer->id) {
            return $filer;
        }

        $target = Employee::query()
            ->with('group')
            ->find($empId);

        if ($target === null) {
            throw ValidationException::withMessages([
                'emp_id' => ['The selected employee does not exist.'],
            ]);
        }

        if (! $target->isActive()) {
            throw ValidationException::withMessages([
                'emp_id' => ['The selected employee is not active.'],
            ]);
        }

        if (! $this->canFileFor($filer, $target)) {
            throw new AuthorizationException(
                'You are not allowed to file leave for this employee.'
            );
        }

        return $target;
    }

    /**
     * Active employees in the group that this PIC may file for (same matrix as POST /leaves).
     * Excludes the filer. Throws if the viewer is not a PIC for this group.
     *
     * @return Collection<int, Employee>
     *
     * @throws AuthorizationException
     */
    public function eligibleMembersInGroup(Employee $filer, Group $group): Collection
    {
        $filerContext = $this->approverResolver->contextFor($filer->id);
        $filerLevel = $this->picLevel($filerContext->approvalLevel);

        if ($filerLevel === null || ! in_array($group->abbreviation, $filerContext->groupCodes, true)) {
            throw new AuthorizationException(
                'You are not allowed to file leave for members of this group.',
            );
        }

        $empIds = $this->approverResolver->employeeIdsInEffectiveGroups(
            [(int) $group->id],
            (int) $filer->id,
            activeOnly: true,
        );

        if ($empIds === []) {
            return collect();
        }

        $members = Employee::query()
            ->whereIn('id', $empIds)
            ->orderBy('id')
            ->get();

        $levels = $this->approverResolver->approvalLevelsFor(
            $members->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        return $members
            ->filter(function (Employee $member) use ($filerLevel, $levels): bool {
                $targetLevel = $this->picLevel($levels[(int) $member->id] ?? null);

                return $this->filerLevelMayActForTarget($filerLevel, $targetLevel);
            })
            ->values();
    }

    public function canFileFor(Employee $filer, Employee $target): bool
    {
        $filerContext = $this->approverResolver->contextFor($filer->id);
        $filerLevel = $this->picLevel($filerContext->approvalLevel);

        // Only P1–P4 may file on behalf of someone else.
        if ($filerLevel === null) {
            return false;
        }

        $targetGroupAbbreviation = $this->approverResolver->filerGroupAbbreviation((int) $target->id);

        if ($targetGroupAbbreviation === null) {
            return false;
        }

        // Filer's PIC scope must include the person-on-leave's primary group.
        if (! in_array($targetGroupAbbreviation, $filerContext->groupCodes, true)) {
            return false;
        }

        $targetContext = $this->approverResolver->contextFor($target->id);
        $targetLevel = $this->picLevel($targetContext->approvalLevel);

        return $this->filerLevelMayActForTarget($filerLevel, $targetLevel);
    }

    /**
     * Normalize formspic.fldRole to P1–P4, or null for regulars / unknown.
     */
    private function picLevel(?int $approvalLevel): ?int
    {
        if ($approvalLevel === null || $approvalLevel < 1 || $approvalLevel > 4) {
            return null;
        }

        return $approvalLevel;
    }

    /**
     * Role matrix: higher PICs cover lower ones; only P1/P2 cover regulars.
     * P4-as-target (President) is intentionally not allowed yet.
     */
    private function filerLevelMayActForTarget(int $filerLevel, ?int $targetLevel): bool
    {
        return match ($targetLevel) {
            null => in_array($filerLevel, [1, 2], true),
            1 => in_array($filerLevel, [2, 3, 4], true),
            2 => in_array($filerLevel, [3, 4], true),
            3 => $filerLevel === 4,
            4 => false,
            default => false,
        };
    }
}
