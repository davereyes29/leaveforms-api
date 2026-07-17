<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Authorizes filing leave on behalf of another employee.
 *
 * Policy: P1 (Group Leader) may file for active members whose primary group
 * is in the leader's formspic.fldGroups.
 */
class LeaveOnBehalfAuthorizationService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * Resolve who the leave is for — defaults to the logged-in employee.
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

    public function canFileFor(Employee $filer, Employee $target): bool
    {
        $context = $this->approverResolver->contextFor($filer->id);

        if ($context->approvalLevel !== 1) {
            return false;
        }

        $targetGroupAbbreviation = $target->group?->abbreviation;

        if ($targetGroupAbbreviation === null) {
            return false;
        }

        return in_array($targetGroupAbbreviation, $context->groupCodes, true);
    }
}
