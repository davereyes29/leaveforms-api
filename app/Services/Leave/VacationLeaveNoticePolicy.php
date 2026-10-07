<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Self-filed VL advance notice (on-behalf / File Leave for Members skips this).
 *
 * Below 3.00 workdays: no gate (same-day and late filing stay allowed).
 * 3.00 up to but not including 4.00 workdays: start ≥ today + 7 calendar days.
 * 4.00 or more workdays: start ≥ today + 30 calendar days.
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

        $threeDayThreshold = (string) config('leave.vacation_leave.notice_threshold_days', '3.00');

        if (bccomp($duration, $threeDayThreshold, 2) < 0) {
            return;
        }

        $longThreshold = (string) config('leave.vacation_leave.long_notice_threshold_days', '4.00');
        $isLong = bccomp($duration, $longThreshold, 2) >= 0;

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = CarbonImmutable::parse($startDate, $tz)->startOfDay();
        $calendarDays = $isLong
            ? (int) config('leave.vacation_leave.long_notice_calendar_days', 30)
            : (int) config('leave.vacation_leave.three_day_notice_calendar_days', 7);
        $minStart = $today->addDays($calendarDays);

        if ($start->lt($minStart)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    $isLong
                        ? 'Leave requests of 4 or more consecutive working days must be filed at least 30 calendar days before the leave start date.'
                        : 'Leave requests of 3 consecutive working days must be filed at least 7 calendar days before the leave start date.',
                ],
            ]);
        }
    }
}
