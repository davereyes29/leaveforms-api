<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Leave\LeaveInfo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries for leave request lists.
 */
class LeaveListService
{
    public function paginateForEmployee(
        int $empId,
        ?LeaveStatus $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return LeaveInfo::query()
            ->with('leaveType')
            ->where('emp_id', $empId)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
