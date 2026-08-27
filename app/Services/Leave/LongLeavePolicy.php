<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Tenure and cooldown gates for Long Leave.
 */
class LongLeavePolicy
{
    public function assertEligibleToFile(Employee $employee, string $startDate, ?int $exceptLeaveId = null): void
    {
        $this->assertTenure($employee);
        $this->assertNoPendingRequest((int) $employee->id, $exceptLeaveId);
        $this->assertCooldown((int) $employee->id, $startDate, $exceptLeaveId);
    }

    private function assertTenure(Employee $employee): void
    {
        $minYears = (int) config('leave.long_leave.min_tenure_years', 5);

        if ($employee->date_hired === null) {
            throw ValidationException::withMessages([
                'code' => ['Long Leave requires a hire date and at least '.$minYears.' years of service.'],
            ]);
        }

        $years = $this->yearsOfService($employee);

        if ($years < $minYears) {
            throw ValidationException::withMessages([
                'code' => [
                    'Long Leave requires at least '.$minYears.' years of service. Current tenure: '.$years.' year(s).',
                ],
            ]);
        }
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

    private function assertCooldown(int $empId, string $startDate, ?int $exceptLeaveId): void
    {
        $llTypeId = LeaveType::query()->where('code', 'LL')->value('id');

        if ($llTypeId === null) {
            return;
        }

        $lastConfirmed = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $llTypeId)
            ->where('status', LeaveStatus::Confirmed)
            ->when($exceptLeaveId !== null, fn ($query) => $query->where('id', '!=', $exceptLeaveId))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();

        if ($lastConfirmed === null) {
            return;
        }

        $cooldownYears = (int) config('leave.long_leave.cooldown_years', 10);
        $eligibleOn = CarbonImmutable::parse($lastConfirmed->end_date->toDateString())
            ->addYears($cooldownYears);
        $start = CarbonImmutable::parse($startDate)->startOfDay();

        if ($start->lt($eligibleOn)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'Long Leave can be filed again on '.$eligibleOn->toDateString()
                    .' ('.$cooldownYears.' years after the last confirmed Long Leave ended).',
                ],
            ]);
        }
    }

    private function yearsOfService(Employee $employee): int
    {
        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $asOf = CarbonImmutable::now($tz)->startOfDay();
        $hired = CarbonImmutable::parse($employee->date_hired->toDateString(), $tz)->startOfDay();

        if ($hired->gt($asOf)) {
            return 0;
        }

        $years = $asOf->year - $hired->year;

        if ($asOf->month < $hired->month
            || ($asOf->month === $hired->month && $asOf->day < $hired->day)) {
            $years--;
        }

        return max(0, $years);
    }
}
