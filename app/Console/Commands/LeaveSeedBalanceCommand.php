<?php

namespace App\Console\Commands;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Local-only helper to grant test leave credits without writing SQL by hand.
 */
class LeaveSeedBalanceCommand extends Command
{
    protected $signature = 'leave:seed-balance
                            {emp_id : employee_list.id — copy from GET /api/me}
                            {amount=15 : Credits to grant, e.g. 15 for VL}
                            {--code=VL : Leave type code}
                            {--force : Add credits even if a balance already exists}
                            {--remarks= : Optional note stored on the ledger row}';

    protected $description = 'Grant dev leave credits (local environment only)';

    public function __construct(
        private readonly LeaveBalanceService $balanceService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs when APP_ENV=local.');

            return self::FAILURE;
        }

        $empId = (int) $this->argument('emp_id');
        $code = strtoupper((string) $this->option('code'));
        $force = (bool) $this->option('force');
        $remarks = $this->option('remarks') ?: 'Dev seed via leave:seed-balance';

        try {
            $amount = $this->formatAmount((string) $this->argument('amount'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->employeeExists($empId)) {
            $this->components->error("Employee {$empId} was not found in kdtphdb_new.employee_list.");

            return self::FAILURE;
        }

        $leaveType = LeaveType::query()->where('code', $code)->first();

        if ($leaveType === null) {
            $this->components->error("Leave type code [{$code}] does not exist.");

            return self::FAILURE;
        }

        try {
            $balance = $force
                ? $this->balanceService->applyManualAdjustment($empId, $leaveType->id, $amount, $remarks)
                : $this->balanceService->applyInitialBalance($empId, $leaveType->id, $amount, $remarks);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Granted {$amount} {$code} credits to employee {$empId}.");
        $this->table(
            ['Field', 'Value'],
            [
                ['available', (string) $balance->available],
                ['reserved', (string) $balance->reserved],
                ['ledger reason', $force ? 'manual_adjustment' : 'initial_balance'],
            ],
        );

        $this->line('Verify: GET /api/balances?code='.$code.' (with portal cookie)');

        return self::SUCCESS;
    }

    private function employeeExists(int $empId): bool
    {
        return Employee::query()->whereKey($empId)->exists();
    }

    private function formatAmount(string $amount): string
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('Amount must be a number greater than 0.');
        }

        return bcadd($amount, '0', 2);
    }
}
