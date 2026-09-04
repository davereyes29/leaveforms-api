<?php

namespace App\Services\Leave;

use App\Enums\Leave\BalanceTransactionReason;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local demo data for the Leave Monetization Candidates and Employees with
 * LWOP admin reports (System Group employees 517 / 542 / 543 and 510 / 541).
 *
 * Monetization leftover credits stay strictly above the retain floors.
 * LWOP leftover credits stay at or below those floors so the two reports
 * do not overlap. LWOP demo leaves use up that type's starting credits
 * first, then extra unpaid days.
 */
class LeaveReportsDemoSeedService
{
    public const REASON_PREFIX = '[DEMO]';

    /** @var list<int> */
    public const MONETIZATION_EMP_IDS = [517, 542, 543];

    /** @var list<int> */
    public const LWOP_EMP_IDS = [510, 541];

    public function __construct(
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * @param  array<int, Employee>  $employees  keyed by employee_list.id
     * @return array{
     *     year: int,
     *     monetization: list<array{emp_id: int, vl_available: string, sl_available: string}>,
     *     lwop: list<array{emp_id: int, leaves: int, vl_available: string, sl_available: string, total_lwop_days: string}>
     * }
     */
    public function seed(array $employees): array
    {
        $this->assertEmployees($employees);

        $tz = $this->timezone();
        $year = CarbonImmutable::now($tz)->year;
        $types = $this->vlAndSlTypes();

        return DB::transaction(function () use ($employees, $year, $types) {
            $monetization = [];

            foreach ($this->monetizationTargets() as $empId => $targets) {
                $this->removePreviousDemoLeaves($empId);
                $this->ensureAvailable($empId, (int) $types['VL']->id, $targets['vl'], $year);
                $this->ensureAvailable($empId, (int) $types['SL']->id, $targets['sl'], $year);

                $monetization[] = [
                    'emp_id' => $empId,
                    'vl_available' => $this->balanceService->availableFor($empId, (int) $types['VL']->id),
                    'sl_available' => $this->balanceService->availableFor($empId, (int) $types['SL']->id),
                ];
            }

            $lwop = [];

            foreach ($this->lwopScenarios($year) as $empId => $scenario) {
                $this->removePreviousDemoLeaves($empId);
                $this->grantAnnualEntitlementIfMissing(
                    $empId,
                    (int) $types['VL']->id,
                    $scenario['vl_entitlement'],
                    $year,
                );
                $this->grantAnnualEntitlementIfMissing(
                    $empId,
                    (int) $types['SL']->id,
                    $scenario['sl_entitlement'],
                    $year,
                );

                $totalLwop = '0.00';

                foreach ($scenario['leaves'] as $leave) {
                    $this->createConfirmedLeave(
                        $employees[$empId],
                        $types[$leave['code']],
                        $leave,
                    );
                    $totalLwop = bcadd($totalLwop, $leave['lwop_days'], 2);
                }

                $this->ensureAvailable($empId, (int) $types['VL']->id, $scenario['leftover_vl'], $year, backdate: false);
                $this->ensureAvailable($empId, (int) $types['SL']->id, $scenario['leftover_sl'], $year, backdate: false);

                $lwop[] = [
                    'emp_id' => $empId,
                    'leaves' => count($scenario['leaves']),
                    'vl_available' => $this->balanceService->availableFor($empId, (int) $types['VL']->id),
                    'sl_available' => $this->balanceService->availableFor($empId, (int) $types['SL']->id),
                    'total_lwop_days' => $totalLwop,
                ];
            }

            return [
                'year' => $year,
                'monetization' => $monetization,
                'lwop' => $lwop,
            ];
        });
    }

    /**
     * @return array<int, array{vl: string, sl: string}>
     */
    public function monetizationTargets(): array
    {
        return [
            517 => ['vl' => '15.00', 'sl' => '32.00'],
            542 => ['vl' => '14.00', 'sl' => '20.00'],
            543 => ['vl' => '8.00', 'sl' => '35.00'],
        ];
    }

    /**
     * @return array<int, array{
     *     vl_entitlement: string,
     *     sl_entitlement: string,
     *     leftover_vl: string,
     *     leftover_sl: string,
     *     leaves: list<array{code: string, paid_days: string, lwop_days: string, start_date: string, end_date: string}>
     * }>
     */
    public function lwopScenarios(int $year): array
    {
        return [
            510 => [
                'vl_entitlement' => '15.00',
                'sl_entitlement' => '18.00',
                'leftover_vl' => '0.00',
                'leftover_sl' => '18.00',
                'leaves' => [
                    [
                        'code' => 'VL',
                        'paid_days' => '15.00',
                        'lwop_days' => '2.00',
                        'start_date' => $year.'-03-02',
                        'end_date' => $year.'-03-24',
                    ],
                ],
            ],
            541 => [
                'vl_entitlement' => '15.00',
                'sl_entitlement' => '18.00',
                // Cap leftover VL at the retain floor so 541 is not a monetization candidate.
                'leftover_vl' => '10.00',
                'leftover_sl' => '0.00',
                'leaves' => [
                    [
                        'code' => 'SL',
                        'paid_days' => '18.00',
                        'lwop_days' => '3.00',
                        'start_date' => $year.'-04-06',
                        'end_date' => $year.'-05-04',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, Employee>  $employees
     */
    private function assertEmployees(array $employees): void
    {
        foreach ([...self::MONETIZATION_EMP_IDS, ...self::LWOP_EMP_IDS] as $empId) {
            if (! isset($employees[$empId])) {
                throw new RuntimeException("Employee {$empId} is required for the leave reports demo seeder.");
            }
        }
    }

    /**
     * @return array{VL: LeaveType, SL: LeaveType}
     */
    private function vlAndSlTypes(): array
    {
        $types = LeaveType::query()
            ->whereIn('code', ['VL', 'SL'])
            ->get()
            ->keyBy('code');

        foreach (['VL', 'SL'] as $code) {
            if (! $types->has($code)) {
                throw new RuntimeException("Leave type [{$code}] not found. Run LeaveTypeSeeder first.");
            }
        }

        return [
            'VL' => $types->get('VL'),
            'SL' => $types->get('SL'),
        ];
    }

    private function ensureAvailable(
        int $empId,
        int $leaveTypeId,
        string $target,
        int $year,
        bool $backdate = true,
    ): void {
        $current = $this->balanceService->availableFor($empId, $leaveTypeId);
        $delta = bcsub($target, $current, 2);

        if (bccomp($delta, '0', 2) === 0) {
            return;
        }

        $this->balanceService->applyManualAdjustment(
            $empId,
            $leaveTypeId,
            $delta,
            self::REASON_PREFIX.' Leave reports demo seeder',
        );

        if (! $backdate) {
            return;
        }

        $this->backdateLatestTransaction(
            $empId,
            $leaveTypeId,
            BalanceTransactionReason::ManualAdjustment,
            $this->januaryGrantAt($year),
        );
    }

    private function grantAnnualEntitlementIfMissing(
        int $empId,
        int $leaveTypeId,
        string $amount,
        int $year,
    ): void {
        [$windowStart, $windowEnd] = $this->yearWindowUtc($year);

        $exists = LeaveBalanceTransaction::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->exists();

        if ($exists) {
            return;
        }

        $this->balanceService->applyAnnualEntitlement(
            $empId,
            $leaveTypeId,
            $amount,
            self::REASON_PREFIX." Annual entitlement {$year}",
        );

        $this->backdateLatestTransaction(
            $empId,
            $leaveTypeId,
            BalanceTransactionReason::AnnualEntitlement,
            $this->januaryGrantAt($year),
        );
    }

    /**
     * @param  array{code: string, paid_days: string, lwop_days: string, start_date: string, end_date: string}  $leave
     */
    private function createConfirmedLeave(Employee $employee, LeaveType $type, array $leave): void
    {
        $duration = bcadd($leave['paid_days'], $leave['lwop_days'], 2);

        LeaveInfo::query()->create([
            'emp_id' => $employee->id,
            'filed_by_emp_id' => $employee->id,
            'leave_type_id' => $type->id,
            'duration' => $duration,
            'paid_days' => $leave['paid_days'],
            'lwop_days' => $leave['lwop_days'],
            'day_type' => DayType::Full,
            'start_date' => $leave['start_date'],
            'end_date' => $leave['end_date'],
            'leave_reason' => self::REASON_PREFIX.' '.$type->code.' for leave reports demo',
            'status' => LeaveStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    private function removePreviousDemoLeaves(int $empId): void
    {
        $ids = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_reason', 'like', self::REASON_PREFIX.'%')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        LeaveBalanceTransaction::query()->whereIn('leave_id', $ids)->delete();
        LeaveInfo::query()->whereIn('id', $ids)->delete();
    }

    private function backdateLatestTransaction(
        int $empId,
        int $leaveTypeId,
        BalanceTransactionReason $reason,
        CarbonImmutable $at,
    ): void {
        $id = LeaveBalanceTransaction::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', $reason)
            ->orderByDesc('id')
            ->value('id');

        if ($id === null) {
            return;
        }

        LeaveBalanceTransaction::query()->where('id', $id)->update([
            'created_at' => $at->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    private function januaryGrantAt(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 6, 9, 0, 0, $this->timezone());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function yearWindowUtc(int $year): array
    {
        $tz = $this->timezone();
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $tz)->utc();
        $end = CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, $tz)->utc();

        return [$start, $end];
    }

    private function timezone(): string
    {
        return (string) config('leave.reminders.timezone', 'Asia/Manila');
    }
}
