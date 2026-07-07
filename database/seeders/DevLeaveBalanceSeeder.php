<?php

namespace Database\Seeders;

use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Optional dev seeder — reads DEV_EMP_ID and DEV_VL_BALANCE from .env.
 *
 * Run manually:
 *   php artisan db:seed --class=DevLeaveBalanceSeeder
 *
 * Prefer the command for one-off testing:
 *   php artisan leave:seed-balance {emp_id}
 */
class DevLeaveBalanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DevLeaveBalanceSeeder skipped — not in local environment.');

            return;
        }

        $empId = (int) env('DEV_EMP_ID', 518);
        $amount = env('DEV_VL_BALANCE', '15.00');

        if ($empId <= 0) {
            $this->command?->warn('Set DEV_EMP_ID in .env to use DevLeaveBalanceSeeder.');

            return;
        }

        $vl = LeaveType::query()->where('code', 'VL')->first();

        if ($vl === null) {
            $this->command?->error('VL leave type not found. Run LeaveTypeSeeder first.');

            return;
        }

        $service = app(LeaveBalanceService::class);

        try {
            $service->applyInitialBalance(
                $empId,
                $vl->id,
                bcadd((string) $amount, '0', 2),
                'DevLeaveBalanceSeeder',
            );
        } catch (RuntimeException $exception) {
            $this->command?->warn($exception->getMessage());
            $this->command?->line('Tip: php artisan leave:seed-balance '.$empId.' --force');

            return;
        }

        $this->command?->info("Dev VL balance seeded for employee {$empId}.");
    }
}
