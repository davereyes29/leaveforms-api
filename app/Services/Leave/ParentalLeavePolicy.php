<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Validation\ValidationException;

/**
 * Gender, pending, and lifetime gates for Maternity and Paternity Leave.
 */
class ParentalLeavePolicy
{
    public function assertEligibleToFile(Employee $employee, string $code, ?int $exceptLeaveId = null): void
    {
        $code = strtoupper($code);

        $this->assertGender($employee, $code);
        $this->assertNoPendingRequest((int) $employee->id, $code, $exceptLeaveId);

        if ($code === 'PL') {
            $this->assertPaternityLifetimeCap((int) $employee->id, $exceptLeaveId);
        }
    }

    private function assertGender(Employee $employee, string $code): void
    {
        if ($code === 'ML' && ! $employee->isFemale()) {
            throw ValidationException::withMessages([
                'code' => ['Maternity Leave can only be filed for female employees.'],
            ]);
        }

        if ($code === 'PL' && ! $employee->isMale()) {
            throw ValidationException::withMessages([
                'code' => ['Paternity Leave can only be filed for male employees.'],
            ]);
        }
    }

    private function assertNoPendingRequest(int $empId, string $code, ?int $exceptLeaveId): void
    {
        $typeId = LeaveType::query()->where('code', $code)->value('id');

        if ($typeId === null) {
            return;
        }

        $pending = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $typeId)
            ->where('status', LeaveStatus::PendingApproval)
            ->when($exceptLeaveId !== null, fn ($query) => $query->where('id', '!=', $exceptLeaveId))
            ->exists();

        if ($pending) {
            $label = $code === 'ML' ? 'Maternity Leave' : 'Paternity Leave';

            throw ValidationException::withMessages([
                'code' => ['A '.$label.' request is already pending approval. Wait for that decision before filing another.'],
            ]);
        }
    }

    private function assertPaternityLifetimeCap(int $empId, ?int $exceptLeaveId): void
    {
        $typeId = LeaveType::query()->where('code', 'PL')->value('id');

        if ($typeId === null) {
            return;
        }

        $max = (int) config('leave.parental_leave.pl_max_confirmed', 4);

        $confirmed = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $typeId)
            ->where('status', LeaveStatus::Confirmed)
            ->when($exceptLeaveId !== null, fn ($query) => $query->where('id', '!=', $exceptLeaveId))
            ->count();

        if ($confirmed >= $max) {
            throw ValidationException::withMessages([
                'code' => [
                    'Paternity Leave can be used for at most '.$max.' deliveries. This employee already has '.$confirmed.' confirmed Paternity Leave request(s).',
                ],
            ]);
        }
    }
}
