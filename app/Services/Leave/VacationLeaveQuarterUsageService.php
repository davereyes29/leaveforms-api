<?php

namespace App\Services\Leave;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\VacationLeaveQuarterLevel;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * VL workdays already confirmed in a calendar quarter, plus this request.
 *
 * Taken counts confirmed and pending_conversion VL only. Converted-to-SL/EL,
 * pending, rejected, and cancelled do not count. Pending this request is
 * added separately as this_request (preview). Never blocks filing.
 *
 * @phpstan-type QuarterSlice array{
 *     quarter: string,
 *     period: array{start_date: string, end_date: string},
 *     taken: string,
 *     this_request: string,
 *     projected: string,
 *     cap: string,
 *     level: string
 * }
 * @phpstan-type Usage array{
 *     applicable: bool,
 *     quarter: ?string,
 *     period: ?array{start_date: string, end_date: string},
 *     taken: string,
 *     this_request: string,
 *     projected: string,
 *     cap: string,
 *     level: string,
 *     quarters: list<QuarterSlice>
 * }
 */
class VacationLeaveQuarterUsageService
{
    /** @var list<LeaveStatus> */
    public const TAKEN_STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::PendingConversion,
    ];

    public function __construct(
        private readonly LeaveDurationService $durationService,
    ) {}

    /**
     * Preview: confirmed VL in overlapping quarter(s) + this request's days.
     *
     * @return Usage
     */
    public function preview(
        int $empId,
        string $startDate,
        string $endDate,
        DayType $dayType,
        string $leaveTypeCode = 'VL',
    ): array {
        $cap = $this->cap();

        if (strtoupper($leaveTypeCode) !== 'VL') {
            return $this->notApplicable($cap);
        }

        $this->durationService->calculate($startDate, $endDate, $dayType, 'VL');

        return $this->build($empId, $startDate, $endDate, $dayType, includeRequestInTaken: false);
    }

    /**
     * After this VL is confirmed: taken already includes it. projected = taken.
     *
     * @return Usage
     */
    public function afterConfirmed(LeaveInfo $leave): array
    {
        $leave->loadMissing('leaveType');

        if (strtoupper((string) $leave->leaveType?->code) !== 'VL') {
            return $this->notApplicable($this->cap());
        }

        return $this->build(
            (int) $leave->emp_id,
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString(),
            $leave->day_type,
            includeRequestInTaken: true,
        );
    }

    public function exceedsCap(array $usage): bool
    {
        return ($usage['level'] ?? '') === VacationLeaveQuarterLevel::Exceeded->value;
    }

    /**
     * @return Usage
     */
    private function build(
        int $empId,
        string $startDate,
        string $endDate,
        DayType $dayType,
        bool $includeRequestInTaken,
    ): array {
        $cap = $this->cap();
        $vlTypeId = LeaveType::query()->where('code', 'VL')->value('id');
        $slices = [];

        foreach ($this->quartersOverlapping($startDate, $endDate) as $quarter) {
            $taken = $vlTypeId === null
                ? '0.00'
                : $this->takenInQuarter($empId, (int) $vlTypeId, $quarter['start_date'], $quarter['end_date']);
            $thisRequest = $this->daysInPeriod($startDate, $endDate, $dayType, $quarter['start_date'], $quarter['end_date']);

            if ($includeRequestInTaken) {
                $projected = $taken;
            } else {
                $projected = bcadd($taken, $thisRequest, 2);
            }

            $slices[] = [
                'quarter' => $quarter['label'],
                'period' => [
                    'start_date' => $quarter['start_date'],
                    'end_date' => $quarter['end_date'],
                ],
                'taken' => $taken,
                'this_request' => $thisRequest,
                'projected' => $projected,
                'cap' => $cap,
                'level' => $this->level($projected, $cap)->value,
            ];
        }

        $primary = $this->pickPrimary($slices);

        return [
            'applicable' => true,
            'quarter' => $primary['quarter'],
            'period' => $primary['period'],
            'taken' => $primary['taken'],
            'this_request' => $primary['this_request'],
            'projected' => $primary['projected'],
            'cap' => $cap,
            'level' => $primary['level'],
            'quarters' => $slices,
        ];
    }

    private function takenInQuarter(int $empId, int $vlTypeId, string $qStart, string $qEnd): string
    {
        $leaves = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $vlTypeId)
            ->whereIn('status', self::TAKEN_STATUSES)
            ->whereDate('start_date', '<=', $qEnd)
            ->whereDate('end_date', '>=', $qStart)
            ->get();

        $total = '0.00';

        foreach ($leaves as $leave) {
            $total = bcadd(
                $total,
                $this->daysInPeriod(
                    $leave->start_date->toDateString(),
                    $leave->end_date->toDateString(),
                    $leave->day_type,
                    $qStart,
                    $qEnd,
                ),
                2,
            );
        }

        return $total;
    }

    private function daysInPeriod(
        string $startDate,
        string $endDate,
        DayType $dayType,
        string $clipFrom,
        string $clipTo,
    ): string {
        $holidayDates = $this->durationService->holidayDatesBetween(
            min($startDate, $clipFrom),
            max($endDate, $clipTo),
        );

        if ($dayType !== DayType::Full) {
            if ($startDate < $clipFrom || $startDate > $clipTo) {
                return '0.00';
            }

            if (! $this->durationService->isWorkday(Carbon::parse($startDate), $holidayDates)) {
                return '0.00';
            }

            return '0.50';
        }

        $workdays = $this->durationService->workdaysBetween(
            $startDate,
            $endDate,
            $clipFrom,
            $clipTo,
            $holidayDates,
        );

        return bcadd((string) count($workdays), '0', 2);
    }

    /**
     * @return list<array{label: string, start_date: string, end_date: string}>
     */
    private function quartersOverlapping(string $startDate, string $endDate): array
    {
        $cursor = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();
        $quarters = [];

        while ($cursor->lte($end)) {
            $quarter = $this->quarterContaining($cursor);
            $quarters[] = $quarter;
            $cursor = CarbonImmutable::parse($quarter['end_date'])->addDay()->startOfDay();
        }

        return $quarters;
    }

    /**
     * @return array{label: string, start_date: string, end_date: string}
     */
    private function quarterContaining(CarbonImmutable $date): array
    {
        $year = (int) $date->year;
        $quarter = (int) ceil($date->month / 3);
        $startMonth = (($quarter - 1) * 3) + 1;
        $start = CarbonImmutable::create($year, $startMonth, 1)->startOfDay();
        $end = $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay();

        return [
            'label' => $year.'-Q'.$quarter,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    private function level(string $projected, string $cap): VacationLeaveQuarterLevel
    {
        $cmp = bccomp($projected, $cap, 2);

        if ($cmp > 0) {
            return VacationLeaveQuarterLevel::Exceeded;
        }

        if ($cmp === 0) {
            return VacationLeaveQuarterLevel::AtCap;
        }

        return VacationLeaveQuarterLevel::Ok;
    }

    /**
     * @param  list<QuarterSlice>  $slices
     * @return QuarterSlice
     */
    private function pickPrimary(array $slices): array
    {
        $rank = static function (string $level): int {
            return match ($level) {
                VacationLeaveQuarterLevel::Exceeded->value => 2,
                VacationLeaveQuarterLevel::AtCap->value => 1,
                default => 0,
            };
        };

        $sorted = $slices;
        usort($sorted, static function (array $a, array $b) use ($rank): int {
            $byLevel = $rank($b['level']) <=> $rank($a['level']);

            if ($byLevel !== 0) {
                return $byLevel;
            }

            return bccomp($b['projected'], $a['projected'], 2);
        });

        return $sorted[0];
    }

    /**
     * @return Usage
     */
    private function notApplicable(string $cap): array
    {
        return [
            'applicable' => false,
            'quarter' => null,
            'period' => null,
            'taken' => '0.00',
            'this_request' => '0.00',
            'projected' => '0.00',
            'cap' => $cap,
            'level' => VacationLeaveQuarterLevel::Ok->value,
            'quarters' => [],
        ];
    }

    private function cap(): string
    {
        return bcadd((string) config('leave.vacation_leave.quarterly_cap_days', '9.00'), '0', 2);
    }
}
