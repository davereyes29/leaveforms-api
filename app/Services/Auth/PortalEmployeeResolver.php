<?php

namespace App\Services\Auth;

use App\Models\Kdtph\Employee;
use App\Models\Kdtph\KdtLogin;

/**
 * Resolves the portal userID cookie to an active employee.
 *
 */
class PortalEmployeeResolver
{
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
            ->with(['group', 'jobDesignation'])
            ->find($login->getEmployeeId());

        if ($employee === null || ! $employee->isActive()) {
            return null;
        }

        return $employee;
    }
}
