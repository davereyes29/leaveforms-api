<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Self-filed VL of 3+ workdays must start at least 1 calendar month out.
 * On-behalf (File Leave for Members) skips this gate.
 */
class VacationLeaveNoticePolicy
{
    public function assertAdvanceNotice(
        string $leaveTypeCode,
        string $duration,
        string $startDate,
        bool $isOnBehalf,
    ): void {
        if (strtoupper($leaveTypeCode) !== 'VL' || $isOnBehalf) {
            return;
        }

        $minDays = (string) config('leave.vacation_leave.min_days_requiring_notice', '3.00');

        if (bccomp($duration, $minDays, 2) < 0) {
            return;
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $months = (int) config('leave.vacation_leave.notice_months', 1);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $minStart = $today->addMonthsNoOverflow($months);
        $start = CarbonImmutable::parse($startDate, $tz)->startOfDay();

        if ($start->lt($minStart)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'Leave requests of 3 or more consecutive days must be filed at least 1 month before the leave start date. Ask your approver to file this request for you, or choose a start date at least 1 month from today, or shorten the leave duration.',
                ],
            ]);
        }
    }
}
