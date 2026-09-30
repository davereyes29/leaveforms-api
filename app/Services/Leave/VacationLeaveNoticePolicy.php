<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Self-filed VL advance notice (on-behalf / File Leave for Members skips this).
 *
 * 1–2 workdays: no gate (same-day and late filing stay allowed).
 * 3.00 or more workdays: start ≥ today + 30 calendar days.
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

        $threshold = (string) config('leave.vacation_leave.notice_threshold_days', '3.00');

        if (bccomp($duration, $threshold, 2) < 0) {
            return;
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = CarbonImmutable::parse($startDate, $tz)->startOfDay();
        $calendarDays = (int) config('leave.vacation_leave.long_notice_calendar_days', 30);
        $minStart = $today->addDays($calendarDays);

        if ($start->lt($minStart)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'Leave requests of 3 or more consecutive working days must be filed at least 30 calendar days before the leave start date.',
                ],
            ]);
        }
    }
}
