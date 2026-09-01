<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Validation\ValidationException;

/**
 * Pending-request gate for Long Leave.
 *
 * Date overlap is handled by LeaveOverlapValidator. Duration, unpaid
 * treatment, attachments, and admin approval live elsewhere.
 */
class LongLeavePolicy
{
    public function assertEligibleToFile(int $empId, ?int $exceptLeaveId = null): void
    {
        $this->assertNoPendingRequest($empId, $exceptLeaveId);
    }

    private function assertNoPendingRequest(int $empId, ?int $exceptLeaveId): void
    {
        $llTypeId = LeaveType::query()->where('code', 'LL')->value('id');

        if ($llTypeId === null) {
            return;
        }

        $pending = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $llTypeId)
            ->where('status', LeaveStatus::PendingApproval)
            ->when($exceptLeaveId !== null, fn ($query) => $query->where('id', '!=', $exceptLeaveId))
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'code' => ['A Long Leave request is already pending approval. Wait for that decision before filing another.'],
            ]);
        }
    }
}
