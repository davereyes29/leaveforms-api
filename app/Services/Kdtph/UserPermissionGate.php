<?php

namespace App\Services\Kdtph;

use Illuminate\Support\Facades\DB;

/**
 * Read-only checks against kdtphdb.user_permissions.
 *
 * Grants are assigned by the existing permission app. This API does not write them.
 */
class UserPermissionGate
{
    public function canViewAllEmployeeMonthlyReport(int $empId): bool
    {
        return $this->has($empId, config('leave.all_employee_monthly_report_permission_id'));
    }

    public function has(int $empId, mixed $permissionId): bool
    {
        $permissionId = $this->permissionId($permissionId);

        if ($permissionId === null || $empId < 1) {
            return false;
        }

        return DB::connection('kdtphdb')
            ->table('user_permissions')
            ->where('permission_id', $permissionId)
            ->where('fldEmployeeNum', $empId)
            ->exists();
    }

    private function permissionId(mixed $permissionId): ?int
    {
        if (is_int($permissionId)) {
            return $permissionId > 0 ? $permissionId : null;
        }

        if (is_string($permissionId) && ctype_digit($permissionId)) {
            $permissionId = (int) $permissionId;

            return $permissionId > 0 ? $permissionId : null;
        }

        return null;
    }
}
