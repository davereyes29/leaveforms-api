<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;

/**
 * Who may edit a pending leave (dates, reason, attachments).
 *
 * Self-file: the employee (who is also the filer).
 * On-behalf: the employee on leave or the original filer.
 */
final class LeaveActorAuthorization
{
    public static function mayModify(LeaveInfo $leave, Employee $actor): bool
    {
        $actorId = (int) $actor->id;

        return (int) $leave->emp_id === $actorId
            || (int) $leave->filed_by_emp_id === $actorId;
    }
}
