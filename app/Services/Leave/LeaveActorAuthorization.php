<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;

/**
 * Who may edit a pending leave (dates, reason, attachments).
 *
 * Self-file: the employee (who is also the filer).
 * On-behalf: only the original filer — the member cannot change it.
 */
final class LeaveActorAuthorization
{
    public static function mayModify(LeaveInfo $leave, Employee $actor): bool
    {
        $actorId = (int) $actor->id;
        $isFiler = (int) $leave->filed_by_emp_id === $actorId;

        if ((int) $leave->filed_by_emp_id !== (int) $leave->emp_id) {
            return $isFiler;
        }

        return (int) $leave->emp_id === $actorId;
    }
}
