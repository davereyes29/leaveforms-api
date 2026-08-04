<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Leave\LeaveInfo;
use Illuminate\Validation\ValidationException;

/**
 * Prevents overlapping leave requests for the same employee.
 */
class LeaveOverlapValidator
{
    /**
     * Statuses that still "occupy" calendar dates.
     *
     * Rejected and cancelled leaves free their dates for new filings.
     */
    private const BLOCKING_STATUSES = [
        LeaveStatus::PendingApproval,
        LeaveStatus::Confirmed,
        LeaveStatus::PendingConversion,
        LeaveStatus::ConvertedToSl,
    ];

    /**
     * @throws ValidationException
     */
    public function assertNoOverlap(
        int $empId,
        string $startDate,
        string $endDate,
        ?int $excludeLeaveId = null,
    ): void {
        $conflict = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->whereIn('status', array_map(fn (LeaveStatus $s) => $s->value, self::BLOCKING_STATUSES))
            ->when($excludeLeaveId !== null, fn ($query) => $query->whereKeyNot($excludeLeaveId))
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->orderBy('start_date')
            ->first();

        if ($conflict === null) {
            return;
        }

        throw ValidationException::withMessages([
            'start_date' => [
                sprintf(
                    'These dates overlap with leave request #%d (%s) from %s to %s.',
                    $conflict->id,
                    $conflict->status->value,
                    $conflict->start_date->toDateString(),
                    $conflict->end_date->toDateString(),
                ),
            ],
        ]);
    }
}
