<?php

namespace App\Services\Auth;

use App\Models\Kdtph\Employee;
use App\Models\Kdtph\KdtLogin;
use App\Services\Leave\LeaveApproverResolver;

/**
 * Resolves the portal userID cookie to an active employee.
 */
class PortalEmployeeResolver
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    public function resolveFromCookie(?string $cookieValue): ?Employee
    {
        if ($cookieValue === null || $cookieValue === '') {
            return null;
        }

        $login = KdtLogin::query()
            ->where('fldUserHash', $cookieValue)
            ->first();

        if ($login === null) {
            return null;
        }

        $employee = Employee::query()
            ->with(['group', 'empProf'])
            ->find($login->getEmployeeId());

        if ($employee === null || ! $employee->isActive()) {
            return null;
        }

        return $this->approverResolver->applyEffectiveGroup($employee);
    }
}
