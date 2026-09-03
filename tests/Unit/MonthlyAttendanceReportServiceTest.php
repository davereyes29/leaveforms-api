<?php

namespace Tests\Unit;

use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Models\Kdtph\Employee;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\MonthlyAttendanceMatrix;
use App\Services\Leave\MonthlyAttendanceReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MonthlyAttendanceReportServiceTest extends TestCase
{
    private MonthlyAttendanceReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb_new' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb_new');
        DB::connection('kdtphdb_new')->setPdo(DB::connection()->getPdo());

        $this->createSchema();

        Employee::query()->insert([
            ['id' => 1, 'firstname' => 'Ada', 'surname' => 'Admin', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 8, 'firstname' => 'Alex', 'surname' => 'Employee', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 9, 'firstname' => 'Sam', 'surname' => 'Rivera', 'emp_status' => 1, 'group_id' => 20],
            ['id' => 20, 'firstname' => 'Aaron', 'surname' => 'Young', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 99, 'firstname' => 'Inez', 'surname' => 'Active', 'emp_status' => 0, 'group_id' => 16],
        ]);

        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->andReturn(new ApproverContext(
            empId: 1,
            approvalLevel: null,
            groupCodes: [],
            isPresident: false,
            isAdmin: true,
        ));
        $resolver->shouldReceive('applyEffectiveGroup')->andReturnUsing(
            fn (Employee $employee): Employee => $employee,
        );
        $resolver->shouldReceive('employeeIdsInEffectiveGroups')->andReturnUsing(
            function (array $groupIds, $excludeEmpId = null, bool $activeOnly = false): array {
                $query = Employee::query()->whereIn('group_id', $groupIds);

                if ($activeOnly) {
                    $query->where('emp_status', 1);
                }

                return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
            },
        );

        $duration = Mockery::mock(LeaveDurationService::class)->makePartial();
        $duration->shouldReceive('holidayDatesBetween')->andReturn([]);

        $this->service = new MonthlyAttendanceReportService(
            $resolver,
            $duration,
            new MonthlyAttendanceMatrix($duration),
        );
    }

    public function test_omitted_emp_ids_includes_every_active_employee(): void
    {
        $report = $this->service->forAdmin(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::FirstHalf,
            [],
            null,
        );

        $this->assertSame(
            [1, 8, 9, 20],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
        $this->assertSame(4, $report['employee_count']);
    }

    public function test_omitted_emp_ids_with_group_filters_active_members(): void
    {
        $report = $this->service->forAdmin(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::FirstHalf,
            [],
            16,
        );

        $this->assertSame(
            [1, 8, 20],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
    }

    public function test_explicit_emp_ids_still_select_a_subset(): void
    {
        $report = $this->service->forAdmin(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::FirstHalf,
            [9],
            null,
        );

        $this->assertSame(
            [9],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
        $this->assertSame(1, $report['employee_count']);
    }

    public function test_rows_are_sorted_by_employee_number_not_name(): void
    {
        $report = $this->service->forAdmin(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::FirstHalf,
            [20, 8, 1],
            null,
        );

        $this->assertSame(
            [1, 8, 20],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_info');
        Schema::dropIfExists('leave_type');
        Schema::dropIfExists('employee_list');
        Schema::dropIfExists('group_list');

        Schema::create('group_list', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('abbreviation')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('dept_id')->default(1);
        });

        Schema::create('employee_list', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('firstname');
            $table->string('surname');
            $table->unsignedTinyInteger('emp_status')->default(1);
            $table->unsignedBigInteger('group_id')->nullable();
        });

        Schema::create('leave_type', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('type', 100);
        });

        Schema::create('leave_info', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->unsignedBigInteger('filed_by_emp_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->decimal('duration', 8, 2);
            $table->decimal('paid_days', 8, 2)->default(0);
            $table->decimal('lwop_days', 8, 2)->default(0);
            $table->string('day_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('leave_reason');
            $table->string('leave_attachment')->nullable();
            $table->string('status', 30);
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
    }
}
