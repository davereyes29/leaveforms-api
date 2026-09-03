<?php

namespace Database\Seeders;

use App\Models\Kdtph\Employee;
use App\Services\Leave\HansFajardoDemoSeedService;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Local-only demo leaves for employee 539 (Hans Fajardo).
 *
 * Does not run from DatabaseSeeder. After seeding, leftover available
 * credits are 5 VL / 5 SL / 5 EL (VL reserved = 2.00 for the pending days).
 *
 *   php artisan db:seed --class=HansFajardoDemoSeeder
 */
class HansFajardoDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('HansFajardoDemoSeeder skipped — not in local environment.');

            return;
        }

        $employee = Employee::query()->find(HansFajardoDemoSeedService::EMP_ID);

        if ($employee === null) {
            $this->command?->error('Employee 539 was not found in kdtphdb_new.employee_list.');

            return;
        }

        if (! $this->looksLikeHansFajardo($employee)) {
            $name = trim($employee->firstname.' '.$employee->surname);
            $this->command?->error("Employee 539 is [{$name}], not Hans Fajardo. Aborting.");

            return;
        }

        $approver = $this->resolveApprover($employee);

        try {
            $summary = app(HansFajardoDemoSeedService::class)->seed($employee, $approver);
        } catch (ValidationException $exception) {
            $this->command?->error(collect($exception->errors())->flatten()->implode(' '));

            return;
        } catch (RuntimeException $exception) {
            $this->command?->error($exception->getMessage());

            return;
        }

        $this->command?->info("Demo leaves seeded for employee {$summary['emp_id']} (Hans Fajardo).");
        $this->command?->table(
            ['Field', 'Value'],
            [
                ['leaves', (string) $summary['leaves']],
                ['VL available', $summary['vl_available']],
                ['VL reserved', $summary['vl_reserved']],
                ['SL available', $summary['sl_available']],
                ['EL available', $summary['el_available']],
            ],
        );
        $this->command?->line('Aug 28 confirmed VL → admin cancel');
        $this->command?->line('Sep 1 pending VL → employee cancel');
        $this->command?->line('Sep 2 pending VL → approver reject');
        $this->command?->line('Sep 3 confirmed VL → convert to SL');
        $this->command?->line('Sep 4 confirmed VL → convert to EL');
    }

    private function looksLikeHansFajardo(Employee $employee): bool
    {
        $blob = strtoupper(implode(' ', array_filter([
            (string) $employee->firstname,
            (string) $employee->surname,
            (string) $employee->nickname,
            (string) $employee->username,
        ])));

        return str_contains($blob, 'FAJARDO');
    }

    private function resolveApprover(Employee $employee): Employee
    {
        $adminId = (int) config('leave.admin_emp_id');

        if ($adminId <= 0) {
            return $employee;
        }

        return Employee::query()->find($adminId) ?? $employee;
    }
}
