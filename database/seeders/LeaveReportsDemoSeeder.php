<?php

namespace Database\Seeders;

use App\Models\Kdtph\Employee;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveReportsDemoSeedService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local-only demo data for Leave Monetization Candidates (517, 542, 543)
 * and Employees with LWOP (510, 541). Does not run from DatabaseSeeder.
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=LeaveReportsDemoSeeder
 */
class LeaveReportsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LeaveReportsDemoSeeder skipped — not in local environment.');

            return;
        }

        $empIds = [
            ...LeaveReportsDemoSeedService::MONETIZATION_EMP_IDS,
            ...LeaveReportsDemoSeedService::LWOP_EMP_IDS,
        ];

        $employees = Employee::query()
            ->with('group')
            ->whereIn('id', $empIds)
            ->get()
            ->keyBy(fn (Employee $employee): int => (int) $employee->id);

        $resolver = app(LeaveApproverResolver::class);

        foreach ($empIds as $empId) {
            $employee = $employees->get($empId);

            if ($employee === null) {
                $this->command?->error("Employee {$empId} was not found in kdtphdb_new.employee_list.");

                return;
            }

            if ((int) $employee->emp_status !== 1) {
                $this->command?->error("Employee {$empId} is not active (emp_status must be 1).");

                return;
            }

            $resolver->applyEffectiveGroup($employee);

            if (! $this->looksLikeSystemGroup($employee)) {
                $name = trim($employee->firstname.' '.$employee->surname);
                $group = $employee->group?->abbreviation ?? $employee->group?->name ?? 'none';
                $this->command?->warn("Employee {$empId} ({$name}) effective group is [{$group}], not System Group.");
            }
        }

        try {
            $summary = app(LeaveReportsDemoSeedService::class)->seed($employees->all());
        } catch (RuntimeException $exception) {
            $this->command?->error($exception->getMessage());

            return;
        }

        $this->command?->info("Leave reports demo seeded for Manila year {$summary['year']}.");
        $this->command?->table(
            ['Report', 'Emp', 'VL available', 'SL available', 'LWOP days'],
            [
                ...array_map(
                    fn (array $row): array => [
                        'Monetization',
                        (string) $row['emp_id'],
                        $row['vl_available'],
                        $row['sl_available'],
                        '—',
                    ],
                    $summary['monetization'],
                ),
                ...array_map(
                    fn (array $row): array => [
                        'LWOP',
                        (string) $row['emp_id'],
                        $row['vl_available'],
                        $row['sl_available'],
                        $row['total_lwop_days'],
                    ],
                    $summary['lwop'],
                ),
            ],
        );
        $this->command?->line('Generate Leave Monetization Candidates for this year — employees 517, 542, 543.');
        $this->command?->line('Generate Employees with LWOP for this year — employees 510, 541.');
    }

    private function looksLikeSystemGroup(Employee $employee): bool
    {
        $group = $employee->group;

        if ($group === null) {
            return false;
        }

        $blob = strtoupper(trim(implode(' ', array_filter([
            (string) $group->abbreviation,
            (string) $group->name,
        ]))));

        return str_contains($blob, 'SYS') || str_contains($blob, 'SYSTEM');
    }
}
