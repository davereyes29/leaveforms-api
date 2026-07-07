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

    private function isWorkday(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return ! KdtHoliday::query()
            ->whereDate('fldDate', $date->toDateString())
            ->exists();
    }
}
