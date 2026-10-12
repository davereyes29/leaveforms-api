<?php

namespace Tests\Unit;

use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\MonthlyAttendanceMatrix;
use App\Services\Leave\MonthlyAttendanceReportService;
use App\Services\Leave\SpecialLeavePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class MonthlyAttendanceReportServiceTest extends TestCase
{
    private MonthlyAttendanceReportService $service;

    private ApproverContext $context;

    /** @var list<int> */
    private array $p4Ids = [8, 9, 99];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb_new' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb_new');
        DB::connection('kdtphdb_new')->setPdo(DB::connection()->getPdo());

        $this->createSchema();

        Group::query()->insert([
            ['id' => 16, 'abbreviation' => 'ENV', 'name' => 'Environment', 'dept_id' => 1],
            ['id' => 20, 'abbreviation' => 'BOI', 'name' => 'Board of Investments', 'dept_id' => 1],
        ]);

        Employee::query()->insert([
            ['id' => 1, 'firstname' => 'Ada', 'surname' => 'Admin', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 8, 'firstname' => 'Alex', 'surname' => 'Employee', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 9, 'firstname' => 'Sam', 'surname' => 'Rivera', 'emp_status' => 1, 'group_id' => 20],
            ['id' => 20, 'firstname' => 'Aaron', 'surname' => 'Young', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 99, 'firstname' => 'Inez', 'surname' => 'Active', 'emp_status' => 0, 'group_id' => 16],
        ]);

        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: null,
            groupCodes: [],
            isPresident: false,
            isAdmin: true,
        );

        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->andReturnUsing(fn (): ApproverContext => $this->context);
        $resolver->shouldReceive('applyEffectiveGroup')->andReturnUsing(
            fn (Employee $employee): Employee => $employee,
        );
        $resolver->shouldReceive('employeeIdsInEffectiveGroups')->andReturnUsing(
            function (array $groupIds, $excludeEmpId = null, bool $activeOnly = false): array {
                $query = Employee::query()->whereIn('group_id', $groupIds);

                if ($excludeEmpId !== null) {
                    $query->whereKeyNot($excludeEmpId);
                }

                if ($activeOnly) {
                    $query->where('emp_status', 1);
                }

                return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
            },
        );
        $resolver->shouldReceive('p4EmployeeIds')->andReturnUsing(
            function ($excludeEmpId = null, bool $activeOnly = false): array {
                $query = Employee::query()->whereIn('id', $this->p4Ids);

                if ($excludeEmpId !== null) {
                    $query->whereKeyNot($excludeEmpId);
                }

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
            new SpecialLeavePolicy,
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

    public function test_approver_report_includes_only_active_employees_in_designated_groups(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: 1,
            groupCodes: ['ENV'],
            isPresident: false,
            isAdmin: false,
        );

        $report = $this->service->forApprover(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::FirstHalf,
            [],
            null,
        );

        $this->assertSame(
            [1, 8, 20],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
        $this->assertNull($report['group']);
    }

    public function test_approver_group_filter_narrows_to_one_designated_group(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: 2,
            groupCodes: ['ENV', 'BOI'],
            isPresident: false,
            isAdmin: false,
        );

        $report = $this->service->forApprover(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::Monthly,
            [],
            20,
        );

        $this->assertSame(
            [9],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
        $this->assertSame(20, $report['group']['id']);
    }

    public function test_approver_cannot_request_a_group_outside_designated_groups(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: 1,
            groupCodes: ['ENV'],
            isPresident: false,
            isAdmin: false,
        );

        $this->expectException(AuthorizationException::class);

        $this->service->forApprover(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::Monthly,
            [],
            20,
        );
    }

    public function test_approver_cannot_include_an_employee_outside_designated_groups(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: 1,
            groupCodes: ['ENV'],
            isPresident: false,
            isAdmin: false,
        );

        try {
            $this->service->forApprover(
                Employee::query()->findOrFail(1),
                '2026-07',
                MonthlyAttendanceCutOff::Monthly,
                [9],
                null,
            );
            $this->fail('Expected employees outside the team to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('emp_ids', $exception->errors());
        }
    }

    public function test_president_report_lists_active_p4_employees_and_ignores_group(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: null,
            groupCodes: ['ENV'],
            isPresident: true,
            isAdmin: false,
        );

        $report = $this->service->forApprover(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::Monthly,
            [],
            16,
        );

        $this->assertSame(
            [8, 9],
            array_map(fn (array $row): int => $row['employee']['id'], $report['rows']),
        );
        $this->assertNull($report['group']);
    }

    public function test_employee_list_matches_approver_scope(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: 1,
            groupCodes: ['ENV'],
            isPresident: false,
            isAdmin: false,
        );

        $employees = $this->service->employeesForApprover(
            Employee::query()->findOrFail(1),
            null,
        );

        $ids = $employees->map(fn (Employee $employee): int => (int) $employee->id)->all();
        sort($ids);

        $this->assertSame([1, 8, 20], $ids);
    }

    public function test_non_approver_cannot_view_the_team_report(): void
    {
        $this->context = new ApproverContext(
            empId: 1,
            approvalLevel: null,
            groupCodes: ['ENV'],
            isPresident: false,
            isAdmin: false,
        );

        $this->expectException(AuthorizationException::class);

        $this->service->forApprover(
            Employee::query()->findOrFail(1),
            '2026-07',
            MonthlyAttendanceCutOff::Monthly,
            [],
            null,
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
