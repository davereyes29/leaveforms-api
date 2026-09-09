<?php

namespace App\Services\Leave;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Self-filed VL advance notice (on-behalf / File Leave for Members skips this).
 *
 * 1–2 workdays: no gate (same-day and late filing stay allowed).
 * Exactly 3.00 workdays: start ≥ today + 3 working days.
 * More than 3.00 workdays: start ≥ today + 30 calendar days.
 */
class VacationLeaveNoticePolicy
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
    ) {}

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
        $cmp = bccomp($duration, $threshold, 2);

        if ($cmp < 0) {
            return;
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = CarbonImmutable::parse($startDate, $tz)->startOfDay();

        if ($cmp === 0) {
            $workdays = (int) config('leave.vacation_leave.exact_notice_workdays', 3);
            $minStart = $this->addWorkingDays($today, $workdays);
            $message = 'Leave requests of 3 consecutive working days must be filed at least 3 working days before the leave start date.';
        } else {
            $calendarDays = (int) config('leave.vacation_leave.long_notice_calendar_days', 30);
            $minStart = $today->addDays($calendarDays);
            $message = 'Leave requests of more than 3 consecutive working days must be filed at least 30 calendar days before the leave start date.';
        }

        if ($start->lt($minStart)) {
            throw ValidationException::withMessages([
                'start_date' => [$message],
            ]);
        }
    }

    private function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $cursor = $from;
        $added = 0;
        $guard = 0;
        $limit = max($days * 14, 60);

        while ($added < $days && $guard < $limit) {
            $cursor = $cursor->addDay();
            $guard++;

            if ($this->durationService->isWorkday(Carbon::parse($cursor->toDateString(), $cursor->timezoneName))) {
                $added++;
            }
        }

        return $cursor->startOfDay();
    }
}
