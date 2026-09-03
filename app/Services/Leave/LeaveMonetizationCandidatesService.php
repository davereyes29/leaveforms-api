<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin Leave Monetization Candidates — excess VL / SL above retained floors.
 *
 * Balance as_of = min(today, Nov 30 of conversion year) in Asia/Manila.
 * On or before the cut-off: live leave_balance.available.
 * After the cut-off: latest leave_balance_transaction.available_after on/before
 * end of that cut-off day.
 */
class LeaveMonetizationCandidatesService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function forAdmin(Employee $admin, int $year): array
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view leave monetization candidates.');
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $cutOff = $this->cutOffDate($year, $tz);
        $useLiveBalances = $today->lessThanOrEqualTo($cutOff);
        $asOf = $useLiveBalances ? $today : $cutOff;

        $vlRetain = $this->decimalString(config('leave.monetization.vl_retain', '10.00'));
        $slRetain = $this->decimalString(config('leave.monetization.sl_retain', '30.00'));

        [$vlType, $slType] = $this->vlAndSlTypes();

        $employees = $this->activeEmployees();
        $empIds = $employees
            ->map(fn (Employee $employee): int => (int) $employee->id)
            ->all();

        $balancesByEmp = $useLiveBalances
            ? $this->liveBalancesByEmployee($empIds, (int) $vlType->id, (int) $slType->id)
            : $this->ledgerBalancesByEmployee($empIds, (int) $vlType->id, (int) $slType->id, $asOf);

        $rows = [];
        $totalVlBalance = '0.00';
        $totalVlExcess = '0.00';
        $totalSlBalance = '0.00';
        $totalSlExcess = '0.00';
        $totalEligible = '0.00';

        foreach ($employees as $employee) {
            $empId = (int) $employee->id;
            $vlAvailable = $balancesByEmp[$empId][(int) $vlType->id] ?? '0.00';
            $slAvailable = $balancesByEmp[$empId][(int) $slType->id] ?? '0.00';

            $vlExcessEligible = bccomp($vlAvailable, $vlRetain, 2) > 0;
            $slExcessEligible = bccomp($slAvailable, $slRetain, 2) > 0;

            if (! $vlExcessEligible && ! $slExcessEligible) {
                continue;
            }

            $vlExcess = $vlExcessEligible
                ? $this->decimalString(bcsub($vlAvailable, $vlRetain, 2))
                : null;
            $slExcess = $slExcessEligible
                ? $this->decimalString(bcsub($slAvailable, $slRetain, 2))
                : null;

            $rowEligible = $this->decimalString(bcadd(
                $vlExcess ?? '0.00',
                $slExcess ?? '0.00',
                2,
            ));

            $rows[] = [
                'employee' => $this->employeePayload($employee),
                'vl_balance' => $vlExcessEligible ? $this->decimalString($vlAvailable) : null,
                'vl_retained' => $vlExcessEligible ? $vlRetain : null,
                'vl_excess' => $vlExcess,
                'sl_balance' => $slExcessEligible ? $this->decimalString($slAvailable) : null,
                'sl_retained' => $slExcessEligible ? $slRetain : null,
                'sl_excess' => $slExcess,
                'total_eligible_days' => $rowEligible,
                'eligibility_basis' => $this->eligibilityBasis($vlExcessEligible, $slExcessEligible),
            ];

            if ($vlExcessEligible) {
                $totalVlBalance = bcadd($totalVlBalance, $vlAvailable, 2);
                $totalVlExcess = bcadd($totalVlExcess, $vlExcess, 2);
            }

            if ($slExcessEligible) {
                $totalSlBalance = bcadd($totalSlBalance, $slAvailable, 2);
                $totalSlExcess = bcadd($totalSlExcess, $slExcess, 2);
            }

            $totalEligible = bcadd($totalEligible, $rowEligible, 2);
        }

        return [
            'conversion_year' => $year,
            'balance_cut_off' => $cutOff->toDateString(),
            'as_of' => $asOf->toDateString(),
            'generated_at' => CarbonImmutable::now($tz)->toIso8601String(),
            'eligible_employees' => count($rows),
            'rows' => $rows,
            'grand_totals' => [
                'vl_balance' => $this->decimalString($totalVlBalance),
                'vl_excess_days' => $this->decimalString($totalVlExcess),
                'sl_balance' => $this->decimalString($totalSlBalance),
                'sl_excess_days' => $this->decimalString($totalSlExcess),
                'total_eligible_days' => $this->decimalString($totalEligible),
            ],
        ];
    }

    private function cutOffDate(int $year, string $tz): CarbonImmutable
    {
        $month = (int) config('leave.monetization.balance_cut_off_month', 11);
        $day = (int) config('leave.monetization.balance_cut_off_day', 30);

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $tz)->startOfDay();
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
    private function activeEmployees(): Collection
    {
        $employees = Employee::query()
            ->with('group')
            ->where('emp_status', 1)
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        return $employees->values();
    }

    /**
     * @param  list<int>  $empIds
     * @return array<int, array<int, string>>
     */
    private function liveBalancesByEmployee(array $empIds, int $vlTypeId, int $slTypeId): array
    {
        if ($empIds === []) {
            return [];
        }

        $map = [];

        $rows = LeaveBalance::query()
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->get(['emp_id', 'leave_type_id', 'available']);

        foreach ($rows as $row) {
            $map[(int) $row->emp_id][(int) $row->leave_type_id] = $this->decimalString($row->available);
        }

        return $map;
    }

    /**
     * Latest available_after on or before end of as_of day (Asia/Manila wall clock).
     *
     * @param  list<int>  $empIds
     * @return array<int, array<int, string>>
     */
    private function ledgerBalancesByEmployee(
        array $empIds,
        int $vlTypeId,
        int $slTypeId,
        CarbonImmutable $asOf,
    ): array {
        if ($empIds === []) {
            return [];
        }

        $asOfEnd = $asOf->endOfDay()->utc();

        $transactions = LeaveBalanceTransaction::query()
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->where('created_at', '<=', $asOfEnd)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['emp_id', 'leave_type_id', 'available_after', 'created_at', 'id']);

        $map = [];

        foreach ($transactions as $transaction) {
            $empId = (int) $transaction->emp_id;
            $typeId = (int) $transaction->leave_type_id;

            if (isset($map[$empId][$typeId])) {
                continue;
            }

            $map[$empId][$typeId] = $this->decimalString($transaction->available_after);
        }

        return $map;
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

    private function eligibilityBasis(bool $vlExcess, bool $slExcess): string
    {
        if ($vlExcess && $slExcess) {
            return 'VL and SL';
        }

        return $vlExcess ? 'VL' : 'SL';
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
