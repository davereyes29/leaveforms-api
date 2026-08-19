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
    public function calculate(string $startDate, string $endDate, DayType $dayType): string
    {
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
