<?php

namespace App\Services\Leave\CreditSheet;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets VL and SL available credits to the admin sheet (remaining balance as of August 30).
 *
 * Legacy VL/SL already stored are overwritten. EL and PTB are not changed.
 */
final class LeaveCreditSheetImporter
{
    public const REMARKS = 'August 30, 2026 remaining VL/SL balance from admin credit sheet';

    public function __construct(
        private readonly LeaveCreditSheetReader $reader,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     employees: int,
     *     vl_updated: int,
     *     sl_updated: int,
     *     unchanged: int,
     *     wrote: bool,
     *     missing: list<array{row: int, emp_id: int, name: string}>,
     *     changes: list<array{emp_id: int, name: string, vl_from: string, vl_to: string, sl_from: string, sl_to: string}>
     * }
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $rows = $this->reader->read($path);
        $typeIds = $this->typeIds();
        $found = $this->existingEmployeeIds(array_column($rows, 'emp_id'));

        $missing = [];
        $applicable = [];

        foreach ($rows as $row) {
            if (! isset($found[$row['emp_id']])) {
                $missing[] = [
                    'row' => $row['row'],
                    'emp_id' => $row['emp_id'],
                    'name' => $row['name'],
                ];

                continue;
            }

            $applicable[] = $row;
        }

        $current = $this->currentAvailable($applicable, $typeIds);
        $changes = [];
        $vlUpdated = 0;
        $slUpdated = 0;
        $unchanged = 0;

        foreach ($applicable as $row) {
            $vlFrom = $current[$row['emp_id'].':'.$typeIds['VL']] ?? '0.00';
            $slFrom = $current[$row['emp_id'].':'.$typeIds['SL']] ?? '0.00';
            $vlSame = bccomp($vlFrom, $row['vl'], 2) === 0;
            $slSame = bccomp($slFrom, $row['sl'], 2) === 0;

            if ($vlSame) {
                $unchanged++;
            } else {
                $vlUpdated++;
            }

            if ($slSame) {
                $unchanged++;
            } else {
                $slUpdated++;
            }

            if ($vlSame && $slSame) {
                continue;
            }

            $changes[] = [
                'emp_id' => $row['emp_id'],
                'name' => $row['name'],
                'vl_from' => $vlFrom,
                'vl_to' => $row['vl'],
                'sl_from' => $slFrom,
                'sl_to' => $row['sl'],
            ];
        }

        $wrote = false;

        if (! $dryRun && $missing === []) {
            DB::transaction(function () use ($applicable, $typeIds, $current): void {
                foreach ($applicable as $row) {
                    $this->setAvailable(
                        $row['emp_id'],
                        $typeIds['VL'],
                        $current[$row['emp_id'].':'.$typeIds['VL']] ?? '0.00',
                        $row['vl'],
                    );
                    $this->setAvailable(
                        $row['emp_id'],
                        $typeIds['SL'],
                        $current[$row['emp_id'].':'.$typeIds['SL']] ?? '0.00',
                        $row['sl'],
                    );
                }
            });
            $wrote = true;
        }

        return [
            'dry_run' => $dryRun,
            'employees' => count($rows),
            'vl_updated' => $vlUpdated,
            'sl_updated' => $slUpdated,
            'unchanged' => $unchanged,
            'wrote' => $wrote,
            'missing' => $missing,
            'changes' => $changes,
        ];
    }

    /**
     * @return array{VL: int, SL: int}
     */
    private function typeIds(): array
    {
        $ids = LeaveType::query()->whereIn('code', ['VL', 'SL'])->pluck('id', 'code')->all();

        foreach (['VL', 'SL'] as $code) {
            if (! isset($ids[$code])) {
                throw new RuntimeException("Leave type [{$code}] was not found. Run LeaveTypeSeeder first.");
            }
        }

        return [
            'VL' => (int) $ids['VL'],
            'SL' => (int) $ids['SL'],
        ];
    }

    /**
     * @param  list<int>  $empIds
     * @return array<int, true>
     */
    private function existingEmployeeIds(array $empIds): array
    {
        $found = [];

        foreach (array_chunk($empIds, 500) as $chunk) {
            $ids = Employee::query()->whereIn('id', $chunk)->pluck('id');

            foreach ($ids as $id) {
                $found[(int) $id] = true;
            }
        }

        return $found;
    }

    /**
     * @param  list<array{emp_id: int, vl: string, sl: string}>  $rows
     * @param  array{VL: int, SL: int}  $typeIds
     * @return array<string, string>
     */
    private function currentAvailable(array $rows, array $typeIds): array
    {
        if ($rows === []) {
            return [];
        }

        $map = [];
        $empIds = array_column($rows, 'emp_id');

        $balances = LeaveBalance::query()
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$typeIds['VL'], $typeIds['SL']])
            ->get(['emp_id', 'leave_type_id', 'available']);

        foreach ($balances as $balance) {
            $map[$balance->emp_id.':'.$balance->leave_type_id] = bcadd((string) $balance->available, '0', 2);
        }

        return $map;
    }

    private function setAvailable(int $empId, int $leaveTypeId, string $current, string $target): void
    {
        if (bccomp($current, $target, 2) === 0) {
            return;
        }

        $balance = LeaveBalance::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        $empty = $balance === null
            || (
                bccomp((string) $balance->available, '0', 2) === 0
                && bccomp((string) $balance->reserved, '0', 2) === 0
            );

        if ($empty) {
            if (bccomp($target, '0', 2) === 0) {
                return;
            }

            $this->balanceService->applyInitialBalance($empId, $leaveTypeId, $target, self::REMARKS);

            return;
        }

        $this->balanceService->applyManualAdjustment(
            $empId,
            $leaveTypeId,
            bcsub($target, $current, 2),
            self::REMARKS,
        );
    }
}
