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
    public function calculate(string $startDate, string $endDate, DayType $dayType, string $leaveTypeCode = 'VL'): string
    {
        if (LeaveTypeRules::requiresFullDay($leaveTypeCode) && $dayType !== DayType::Full) {
            throw ValidationException::withMessages([
                'day_type' => ['This leave type can only be filed as a full day.'],
            ]);
        }

        if (LeaveTypeRules::usesCalendarDays($leaveTypeCode)) {
            $this->assertCalendarMonthSpan($startDate, $endDate, $leaveTypeCode);

            return $this->calculateCalendarDays($startDate, $endDate);
        }

        if ($dayType !== DayType::Full) {
            return $this->calculateHalfDay($startDate, $endDate);
        }

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
     * Weekends and holidays are included (PTB, LL).
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
