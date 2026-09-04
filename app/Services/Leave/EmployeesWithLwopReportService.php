<?php

namespace App\Services\Leave;

use App\Enums\Leave\BalanceTransactionReason;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin Employees with LWOP — VL/SL unpaid days for a Manila calendar year.
 *
 * Display period: 1 January of `year` through min(today, 31 December of `year`).
 * Leave totals: every accepted VL/SL overlapping 1 January–31 December of
 * `year`, including dates after today. Starting VL/SL = `available_after` on
 * that year's `annual_entitlement` ledger row (carryover + grant). Consumed =
 * accepted VL/SL days taken (`paid_days` + `lwop_days` of that type). LWOP =
 * `lwop_days` on VL/SL only (not Long Leave).
 */
class EmployeesWithLwopReportService
{
    /** @var list<LeaveStatus> */
    public const STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::PendingConversion,
        LeaveStatus::ConvertedToSl,
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function forAdmin(Employee $admin, int $year, ?int $groupId = null): array
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view the employees with LWOP report.');
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        [$periodStart, $asOf, $yearEnd] = $this->period($year, $tz);
        [$vlType, $slType] = $this->vlAndSlTypes();

        $employees = $this->activeEmployees($groupId);
        $empIds = $employees
            ->map(fn (Employee $employee): int => (int) $employee->id)
            ->all();

        $totalsByEmp = $this->totalsByEmployee(
            $empIds,
            (int) $vlType->id,
            (int) $slType->id,
            $periodStart->toDateString(),
            $yearEnd->toDateString(),
        );

        $lwopEmpIds = [];

        foreach ($totalsByEmp as $empId => $totals) {
            if (bccomp($totals['total_lwop_days'], '0.00', 2) > 0) {
                $lwopEmpIds[] = $empId;
            }
        }

        $startingByEmp = $this->startingBalancesByEmployee(
            $lwopEmpIds,
            $year,
            (int) $vlType->id,
            (int) $slType->id,
        );

        $rows = [];
        $grandVlBalance = '0.00';
        $grandVlConsumed = '0.00';
        $grandSlBalance = '0.00';
        $grandSlConsumed = '0.00';
        $grandLwop = '0.00';

        foreach ($employees as $employee) {
            $empId = (int) $employee->id;

            if (! in_array($empId, $lwopEmpIds, true)) {
                continue;
            }

            $totals = $totalsByEmp[$empId];
            $starting = $startingByEmp[$empId] ?? ['vl' => '0.00', 'sl' => '0.00'];
            $vlBalance = $this->decimalString($starting['vl']);
            $slBalance = $this->decimalString($starting['sl']);

            $rows[] = [
                'employee' => $this->employeePayload($employee),
                'vl_balance' => $vlBalance,
                'vl_consumed' => $totals['vl_consumed'],
                'sl_balance' => $slBalance,
                'sl_consumed' => $totals['sl_consumed'],
                'total_lwop_days' => $totals['total_lwop_days'],
            ];

            $grandVlBalance = bcadd($grandVlBalance, $vlBalance, 2);
            $grandVlConsumed = bcadd($grandVlConsumed, $totals['vl_consumed'], 2);
            $grandSlBalance = bcadd($grandSlBalance, $slBalance, 2);
            $grandSlConsumed = bcadd($grandSlConsumed, $totals['sl_consumed'], 2);
            $grandLwop = bcadd($grandLwop, $totals['total_lwop_days'], 2);
        }

        $group = $groupId !== null ? Group::query()->find($groupId) : null;

        return [
            'year' => $year,
            'as_of' => $asOf->toDateString(),
            'generated_at' => CarbonImmutable::now($tz)->toIso8601String(),
            'period' => [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $asOf->toDateString(),
            ],
            'group' => $this->groupPayload($group),
            'employee_count' => count($rows),
            'rows' => $rows,
            'grand_totals' => [
                'vl_balance' => $this->decimalString($grandVlBalance),
                'vl_consumed' => $this->decimalString($grandVlConsumed),
                'sl_balance' => $this->decimalString($grandSlBalance),
                'sl_consumed' => $this->decimalString($grandSlConsumed),
                'total_lwop_days' => $this->decimalString($grandLwop),
            ],
        ];
    }

    /**
     * Display window is Jan 1 through min(today, Dec 31). Leave query uses the
     * full calendar year so future accepted dates in `year` still count.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function period(int $year, string $tz): array
    {
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $tz)->startOfDay();
        $yearEnd = CarbonImmutable::create($year, 12, 31, 0, 0, 0, $tz)->startOfDay();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $asOf = $today->lessThan($yearEnd) ? $today : $yearEnd;

        if ($asOf->lessThan($start)) {
            $asOf = $start;
        }

        return [$start, $asOf, $yearEnd];
    }

    /**
     * @return array{0: LeaveType, 1: LeaveType}
     *
     * @throws ValidationException
     */
    private function vlAndSlTypes(): array
    {
        $types = LeaveType::query()
            ->whereIn('code', ['VL', 'SL'])
            ->get()
            ->keyBy(fn (LeaveType $type): string => strtoupper((string) $type->code));

        $vl = $types->get('VL');
        $sl = $types->get('SL');

        if ($vl === null || $sl === null) {
            throw ValidationException::withMessages([
                'leave_type' => ['Vacation Leave and Sick Leave types must exist.'],
            ]);
        }

        return [$vl, $sl];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function activeEmployees(?int $groupId): Collection
    {
        $query = Employee::query()
            ->with('group')
            ->where('emp_status', 1);

        if ($groupId !== null) {
            $empIds = $this->approverResolver->employeeIdsInEffectiveGroups(
                [$groupId],
                activeOnly: true,
            );

            if ($empIds === []) {
                return collect();
            }

            $query->whereIn('id', $empIds);
        }

        $employees = $query->get();

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        return $employees
            ->sortBy(fn (Employee $employee): int => (int) $employee->id)
            ->values();
    }

    /**
     * @param  list<int>  $empIds
     * @return array<int, array{vl_consumed: string, sl_consumed: string, total_lwop_days: string}>
     */
    private function totalsByEmployee(
        array $empIds,
        int $vlTypeId,
        int $slTypeId,
        string $start,
        string $end,
    ): array {
        if ($empIds === []) {
            return [];
        }

        $leaves = LeaveInfo::query()
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->whereIn('status', self::STATUSES)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get(['emp_id', 'leave_type_id', 'paid_days', 'lwop_days']);

        $map = [];

        foreach ($leaves as $leave) {
            $empId = (int) $leave->emp_id;

            if (! isset($map[$empId])) {
                $map[$empId] = [
                    'vl_consumed' => '0.00',
                    'sl_consumed' => '0.00',
                    'total_lwop_days' => '0.00',
                ];
            }

            $taken = bcadd(
                $this->decimalString($leave->paid_days),
                $this->decimalString($leave->lwop_days),
                2,
            );
            $lwop = $this->decimalString($leave->lwop_days);

            if ((int) $leave->leave_type_id === $vlTypeId) {
                $map[$empId]['vl_consumed'] = bcadd($map[$empId]['vl_consumed'], $taken, 2);
            }

            if ((int) $leave->leave_type_id === $slTypeId) {
                $map[$empId]['sl_consumed'] = bcadd($map[$empId]['sl_consumed'], $taken, 2);
            }

            $map[$empId]['total_lwop_days'] = bcadd($map[$empId]['total_lwop_days'], $lwop, 2);
        }

        return $map;
    }

    /**
     * Earliest `annual_entitlement` `available_after` in the Manila year, per type.
     *
     * @param  list<int>  $empIds
     * @return array<int, array{vl: string, sl: string}>
     */
    private function startingBalancesByEmployee(
        array $empIds,
        int $year,
        int $vlTypeId,
        int $slTypeId,
    ): array {
        if ($empIds === []) {
            return [];
        }

        [$windowStart, $windowEnd] = $this->yearWindowUtc($year);

        $rows = LeaveBalanceTransaction::query()
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['emp_id', 'leave_type_id', 'available_after']);

        $map = [];
        $seen = [];

        foreach ($empIds as $empId) {
            $map[$empId] = ['vl' => '0.00', 'sl' => '0.00'];
        }

        foreach ($rows as $row) {
            $empId = (int) $row->emp_id;
            $amount = $this->decimalString($row->available_after);

            if ((int) $row->leave_type_id === $vlTypeId && ! isset($seen[$empId]['vl'])) {
                $map[$empId]['vl'] = $amount;
                $seen[$empId]['vl'] = true;

                continue;
            }

            if ((int) $row->leave_type_id === $slTypeId && ! isset($seen[$empId]['sl'])) {
                $map[$empId]['sl'] = $amount;
                $seen[$empId]['sl'] = true;
            }
        }

        return $map;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function yearWindowUtc(int $year): array
    {
        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $tz)->utc();
        $end = CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, $tz)->utc();

        return [$start, $end];
    }

    /**
     * @return array{id: int, employee_number: string, name: string, group: ?array{id: int, abbreviation: string|null, name: string|null}}
     */
    private function employeePayload(Employee $employee): array
    {
        $group = $employee->group;

        return [
            'id' => (int) $employee->id,
            'employee_number' => (string) $employee->id,
            'name' => $employee->fullName(),
            'group' => $group === null ? null : [
                'id' => (int) $group->id,
                'abbreviation' => $group->abbreviation,
                'name' => $group->name,
            ],
        ];
    }

    /**
     * @return array{id: int, abbreviation: string|null, name: string|null, dept_id: int}|null
     */
    private function groupPayload(?Group $group): ?array
    {
        if ($group === null) {
            return null;
        }

        return [
            'id' => (int) $group->id,
            'abbreviation' => $group->abbreviation,
            'name' => $group->name,
            'dept_id' => (int) $group->dept_id,
        ];
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
