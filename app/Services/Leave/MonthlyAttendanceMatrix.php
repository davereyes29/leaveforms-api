<?php

namespace App\Services\Leave;

use App\Enums\Leave\DayType;
use App\Enums\Leave\MonthlyAttendanceCutOff;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Pure matrix math for the admin Monthly Attendance Report.
 *
 * Expand confirmed leave onto duration days, assign paid then LWOP,
 * clip to the cut-off window, and merge at most one cell per date.
 */
class MonthlyAttendanceMatrix
{
    /** @var array<string, int> */
    private const CATEGORY_PRIORITY = [
        'lwop' => 4,
        'sl' => 3,
        'vl' => 2,
        'others' => 1,
    ];

    public function __construct(
        private readonly LeaveDurationService $durationService,
    ) {}

    /**
     * Inclusive cut-off window as Y-m-d.
     *
     * @return array{0: string, 1: string}
     */
    public function period(string $month, MonthlyAttendanceCutOff $cutOff): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $endOfMonth = $start->copy()->endOfMonth()->startOfDay();
        $lastDay = (int) $endOfMonth->format('j');

        return match ($cutOff) {
            MonthlyAttendanceCutOff::Monthly => [
                $start->toDateString(),
                $endOfMonth->toDateString(),
            ],
            MonthlyAttendanceCutOff::FirstHalf => [
                $start->toDateString(),
                $start->copy()->day(min(15, $lastDay))->toDateString(),
            ],
            MonthlyAttendanceCutOff::SecondHalf => [
                $start->copy()->day(16)->toDateString(),
                $endOfMonth->toDateString(),
            ],
        };
    }

    /**
     * Every calendar day in [start, end], including weekends and holidays.
     *
     * @param  list<string>  $holidayDates
     * @return list<array{date: string, day_of_month: int, weekday_short: string, is_non_working: bool}>
     */
    public function dateColumns(string $start, string $end, array $holidayDates): array
    {
        $columns = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $columns[] = [
                'date' => $date->toDateString(),
                'day_of_month' => (int) $date->format('j'),
                'weekday_short' => $date->format('D'),
                'is_non_working' => ! $this->durationService->isWorkday($date, $holidayDates),
            ];
        }

        return $columns;
    }

    /**
     * Sparse cells for one leave, clipped to [clipFrom, clipTo].
     * Paid units are assigned first on the full (unclipped) range so LWOP stays on the tail.
     *
     * @param  list<string>  $holidayDates
     * @return list<array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}>
     */
    public function cellsForLeave(
        string $code,
        DayType $dayType,
        string $startDate,
        string $endDate,
        string $paidDays,
        string $clipFrom,
        string $clipTo,
        array $holidayDates,
        ?bool $calendarDays = null,
    ): array {
        $code = strtoupper($code);
        $useCalendar = $calendarDays ?? LeaveTypeRules::usesCalendarDays($code);
        $dates = $useCalendar
            ? $this->durationService->calendarDatesBetween($startDate, $endDate, $startDate, $endDate)
            : $this->durationService->workdaysBetween($startDate, $endDate, $startDate, $endDate, $holidayDates);

        $units = $this->unitsForDates($dates, $dayType);

        if ($units === []) {
            return [];
        }

        $paidCategory = $this->paidCategory($code);
        $remainingPaid = bcadd($paidDays, '0', 2);
        $cells = [];

        foreach ($units as $unit) {
            $bucket = $this->takeBucket($remainingPaid, $unit['amount'], $paidCategory);
            $remainingPaid = $bucket['remaining_paid'];

            if ($unit['date'] < $clipFrom || $unit['date'] > $clipTo) {
                continue;
            }

            $amount = $bucket['amount'];

            $cells[] = [
                'date' => $unit['date'],
                'category' => $bucket['category'],
                'amount' => $amount,
                'half' => $amount === '1.00' ? null : $unit['half'],
                'is_non_working' => ! $this->durationService->isWorkday(
                    Carbon::parse($unit['date'])->startOfDay(),
                    $holidayDates,
                ),
            ];
        }

        return $cells;
    }

    /**
     * At most one cell per date. Same category AM+PM → 1.00.
     * Different categories: LWOP wins, then SL, VL, others.
     *
     * @param  list<array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}>  $cells
     * @return list<array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}>
     */
    public function mergeCells(array $cells): array
    {
        $byDate = [];

        foreach ($cells as $cell) {
            $date = $cell['date'];

            if (! isset($byDate[$date])) {
                $byDate[$date] = $cell;

                continue;
            }

            $byDate[$date] = $this->mergeTwo($byDate[$date], $cell);
        }

        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * @param  list<array{category: string, amount: string}>  $cells
     * @return array{vl: string, sl: string, others: string, lwop: string}
     */
    public function totalsForCells(array $cells): array
    {
        $totals = [
            'vl' => '0.00',
            'sl' => '0.00',
            'others' => '0.00',
            'lwop' => '0.00',
        ];

        foreach ($cells as $cell) {
            $category = $cell['category'];

            if (! isset($totals[$category])) {
                continue;
            }

            $totals[$category] = bcadd($totals[$category], $cell['amount'], 2);
        }

        return $totals;
    }

    /**
     * @param  list<array{vl: string, sl: string, others: string, lwop: string}>  $rowTotals
     * @return array{vl: string, sl: string, others: string, lwop: string}
     */
    public function grandTotals(array $rowTotals): array
    {
        $grand = [
            'vl' => '0.00',
            'sl' => '0.00',
            'others' => '0.00',
            'lwop' => '0.00',
        ];

        foreach ($rowTotals as $totals) {
            foreach ($grand as $key => $value) {
                $grand[$key] = bcadd($value, $totals[$key] ?? '0.00', 2);
            }
        }

        return $grand;
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{date: string, amount: string, half: string|null}>
     */
    private function unitsForDates(array $dates, DayType $dayType): array
    {
        if ($dates === []) {
            return [];
        }

        if ($dayType !== DayType::Full) {
            return [[
                'date' => $dates[0],
                'amount' => '0.50',
                'half' => $dayType === DayType::HalfAm ? 'am' : 'pm',
            ]];
        }

        $units = [];

        foreach ($dates as $date) {
            $units[] = [
                'date' => $date,
                'amount' => '1.00',
                'half' => null,
            ];
        }

        return $units;
    }

    /**
     * @return array{category: string, amount: string, remaining_paid: string}
     */
    private function takeBucket(string $remainingPaid, string $amount, string $paidCategory): array
    {
        if (bccomp($remainingPaid, $amount, 2) >= 0) {
            return [
                'category' => $paidCategory,
                'amount' => $amount,
                'remaining_paid' => bcsub($remainingPaid, $amount, 2),
            ];
        }

        return [
            'category' => 'lwop',
            'amount' => $amount,
            'remaining_paid' => '0.00',
        ];
    }

    private function paidCategory(string $code): string
    {
        return match (strtoupper($code)) {
            'VL' => 'vl',
            'SL' => 'sl',
            default => 'others',
        };
    }

    /**
     * @param  array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}  $a
     * @param  array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}  $b
     * @return array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}
     */
    private function mergeTwo(array $a, array $b): array
    {
        $sum = bcadd($a['amount'], $b['amount'], 2);

        if (bccomp($sum, '1.00', 2) > 0) {
            $sum = '1.00';
        }

        $aPriority = self::CATEGORY_PRIORITY[$a['category']] ?? 0;
        $bPriority = self::CATEGORY_PRIORITY[$b['category']] ?? 0;
        $winner = $bPriority > $aPriority ? $b : $a;
        $full = bccomp($sum, '1.00', 2) >= 0;

        return [
            'date' => $a['date'],
            'category' => $winner['category'],
            'amount' => $full ? '1.00' : $sum,
            'half' => $full ? null : $winner['half'],
            'is_non_working' => $a['is_non_working'] || $b['is_non_working'],
        ];
    }
}
