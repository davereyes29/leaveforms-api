<?php

namespace App\Services\Leave;

use App\Enums\Leave\DayType;
use App\Models\Kdtph\KdtHoliday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Calculates leave duration from dates, day type, weekends, and holidays.
 */
class LeaveDurationService
{
    public function calculate(
        string $startDate,
        string $endDate,
        DayType $dayType,
        string $leaveTypeCode = 'VL',
        ?SpecialLeaveAllotment $specialLeave = null,
    ): string {
        $code = strtoupper($leaveTypeCode);

        if (LeaveTypeRules::requiresFullDay($code) && $dayType !== DayType::Full) {
            throw ValidationException::withMessages([
                'day_type' => ['This leave type can only be filed as a full day.'],
            ]);
        }

        if ($code === 'SPL') {
            if ($specialLeave === null) {
                throw ValidationException::withMessages([
                    'special_leave_reason' => ['A qualifying event is required to file Special Leave.'],
                ]);
            }

            if ($specialLeave->usesCalendarDays) {
                $this->assertCalendarSpanAtMost($startDate, $endDate, $specialLeave->days, 'Special Leave');

                return $this->calculateCalendarDays($startDate, $endDate);
            }

            return $this->calculateWorkdaysAtMost($startDate, $endDate, $specialLeave->days, 'Special Leave');
        }

        if (LeaveTypeRules::usesCalendarDays($code)) {
            $this->assertCalendarMonthSpan($startDate, $endDate, $code);
            $this->assertExactCalendarDays($startDate, $endDate, $code);

            return $this->calculateCalendarDays($startDate, $endDate);
        }

        if ($dayType !== DayType::Full) {
            return $this->calculateHalfDay($startDate, $endDate);
        }

        $workdays = $this->countWorkdays($startDate, $endDate);
        $this->assertExactWorkdays($startDate, $endDate, $code, $workdays);

        return bcadd((string) $workdays, '0', 2);
    }

    public function calculateCalendarDays(string $startDate, string $endDate): string
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        return bcadd((string) $days, '0', 2);
    }

    private function assertCalendarMonthSpan(string $startDate, string $endDate, string $leaveTypeCode): void
    {
        $bounds = LeaveTypeRules::calendarMonthBounds($leaveTypeCode);

        if ($bounds === null) {
            return;
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $minEnd = $start->copy()->addMonthsNoOverflow($bounds['min'])->subDay();
        $maxEnd = $start->copy()->addMonthsNoOverflow($bounds['max'])->subDay();

        if ($end->lt($minEnd) || $end->gt($maxEnd)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'Long Leave must be at least '.$bounds['min'].' month(s) and at most '.$bounds['max']
                    .' month(s). For a start date of '.$start->toDateString()
                    .', the end date must be between '.$minEnd->toDateString()
                    .' and '.$maxEnd->toDateString().'.',
                ],
            ]);
        }
    }

    private function assertExactCalendarDays(string $startDate, string $endDate, string $leaveTypeCode): void
    {
        $required = LeaveTypeRules::exactCalendarDays($leaveTypeCode);

        if ($required === null) {
            return;
        }

        $this->assertExactCalendarSpan($startDate, $endDate, $required, 'Maternity Leave');
    }

    private function assertExactCalendarSpan(string $startDate, string $endDate, int $required, string $label): void
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $expectedEnd = $start->copy()->addDays($required - 1);

        if (! $end->equalTo($expectedEnd)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    $label.' must be exactly '.$required.' calendar days. For a start date of '
                    .$start->toDateString().', the end date must be '.$expectedEnd->toDateString().'.',
                ],
            ]);
        }
    }

    private function assertExactWorkdays(string $startDate, string $endDate, string $leaveTypeCode, int $workdays): void
    {
        $required = LeaveTypeRules::exactWorkdays($leaveTypeCode);

        if ($required === null) {
            return;
        }

        $this->assertExactWorkdaySpan($startDate, $endDate, $required, $workdays, 'Paternity Leave');
    }

    private function assertCalendarSpanAtMost(string $startDate, string $endDate, int $maximum, string $label): void
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $latestEnd = $start->copy()->addDays($maximum - 1);

        if ($end->lt($start) || $end->gt($latestEnd)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    $label.' for this event can be at most '.$maximum.' calendar days. For a start date of '
                    .$start->toDateString().', the end date must be on or before '.$latestEnd->toDateString().'.',
                ],
            ]);
        }
    }

    private function calculateWorkdaysAtMost(string $startDate, string $endDate, int $maximum, string $label): string
    {
        $start = Carbon::parse($startDate)->startOfDay();

        if (! $this->isWorkday($start)) {
            throw ValidationException::withMessages([
                'start_date' => [$label.' must start on a workday.'],
            ]);
        }

        $workdays = $this->countWorkdays($startDate, $endDate);
        $latestEnd = $this->latestEndWithinWorkdayCap($start, $maximum);
        $end = Carbon::parse($endDate)->startOfDay();

        if ($workdays > $maximum || $end->gt($latestEnd)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    $label.' for this event can be at most '.$maximum.' workdays. For a start date of '
                    .$start->toDateString().', the end date must be on or before '.$latestEnd->toDateString().'.',
                ],
            ]);
        }

        return bcadd((string) $workdays, '0', 2);
    }

    private function latestEndWithinWorkdayCap(Carbon $start, int $maximum): Carbon
    {
        $lastWorkday = $this->nthWorkdayOnOrAfter($start, $maximum);
        $nextWorkday = $this->nthWorkdayOnOrAfter($lastWorkday->copy()->addDay(), 1);

        return $nextWorkday->copy()->subDay();
    }

    private function countWorkdays(string $startDate, string $endDate): int
    {
        $workdays = 0;

        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            if ($this->isWorkday($date)) {
                $workdays++;
            }
        }

        if ($workdays === 0) {
            throw ValidationException::withMessages([
                'start_date' => ['The selected date range has no workdays.'],
            ]);
        }

        return $workdays;
    }

    private function assertExactWorkdaySpan(
        string $startDate,
        string $endDate,
        int $required,
        int $workdays,
        string $label,
    ): void {
        $start = Carbon::parse($startDate)->startOfDay();

        if (! $this->isWorkday($start)) {
            throw ValidationException::withMessages([
                'start_date' => [$label.' must start on a workday.'],
            ]);
        }

        $expectedEnd = $this->nthWorkdayOnOrAfter($start, $required);
        $end = Carbon::parse($endDate)->startOfDay();

        if ($workdays !== $required || ! $end->equalTo($expectedEnd)) {
            throw ValidationException::withMessages([
                'end_date' => [
                    $label.' must be exactly '.$required.' workdays in one request. For a start date of '
                    .$start->toDateString().', the end date must be '.$expectedEnd->toDateString().'.',
                ],
            ]);
        }
    }

    private function nthWorkdayOnOrAfter(Carbon $start, int $n): Carbon
    {
        $found = 0;
        $cursor = $start->copy();

        for ($i = 0; $i < 120; $i++) {
            if ($this->isWorkday($cursor)) {
                $found++;

                if ($found === $n) {
                    return $cursor;
                }
            }

            $cursor = $cursor->addDay();
        }

        throw ValidationException::withMessages([
            'end_date' => ['Could not find '.$n.' workdays from the selected start date.'],
        ]);
    }

    private function calculateHalfDay(string $startDate, string $endDate): string
    {
        if ($startDate !== $endDate) {
            throw ValidationException::withMessages([
                'end_date' => ['Half-day leave must use the same start and end date.'],
            ]);
        }

        if (! $this->isWorkday(Carbon::parse($startDate))) {
            throw ValidationException::withMessages([
                'start_date' => ['The selected date is not a workday.'],
            ]);
        }

        return '0.50';
    }

    /**
     * Holiday dates in [from, to] (inclusive), as Y-m-d strings.
     *
     * @return list<string>
     */
    public function holidayDatesBetween(string $from, string $to): array
    {
        return KdtHoliday::query()
            ->whereDate('fldDate', '>=', $from)
            ->whereDate('fldDate', '<=', $to)
            ->pluck('fldDate')
            ->map(function (mixed $date): string {
                if ($date instanceof \DateTimeInterface) {
                    return $date->format('Y-m-d');
                }

                return (string) $date;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Workdays in [startDate, endDate], clipped to [clipFrom, clipTo].
     * Weekends and $holidayDates are skipped (same rules as duration).
     *
     * @param  list<string>  $holidayDates  Y-m-d
     * @return list<string>
     */
    public function workdaysBetween(
        string $startDate,
        string $endDate,
        string $clipFrom,
        string $clipTo,
        array $holidayDates,
    ): array {
        $from = Carbon::parse($startDate)->max(Carbon::parse($clipFrom))->startOfDay();
        $to = Carbon::parse($endDate)->min(Carbon::parse($clipTo))->startOfDay();

        if ($from->gt($to)) {
            return [];
        }

        $dates = [];

        foreach (CarbonPeriod::create($from, $to) as $date) {
            if ($this->isWorkday($date, $holidayDates)) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Inclusive calendar dates in [startDate, endDate], clipped to [clipFrom, clipTo].
     * Weekends and holidays are included (PTB, LL, ML).
     *
     * @return list<string>
     */
    public function calendarDatesBetween(
        string $startDate,
        string $endDate,
        string $clipFrom,
        string $clipTo,
    ): array {
        $from = Carbon::parse($startDate)->max(Carbon::parse($clipFrom))->startOfDay();
        $to = Carbon::parse($endDate)->min(Carbon::parse($clipTo))->startOfDay();

        if ($from->gt($to)) {
            return [];
        }

        $dates = [];

        foreach (CarbonPeriod::create($from, $to) as $date) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * @param  list<string>|null  $holidayDates  Y-m-d; null queries kdtphdb
     */
    public function isWorkday(Carbon $date, ?array $holidayDates = null): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        $ymd = $date->toDateString();

        if ($holidayDates !== null) {
            return ! in_array($ymd, $holidayDates, true);
        }

        return ! KdtHoliday::query()
            ->whereDate('fldDate', $ymd)
            ->exists();
    }
}
