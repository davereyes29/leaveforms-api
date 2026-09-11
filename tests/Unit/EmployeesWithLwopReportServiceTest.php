<?php

namespace Tests\Unit;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\EmployeesWithLwopReportService;
use App\Services\Leave\LeaveApproverResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class EmployeesWithLwopReportServiceTest extends TestCase
{
    private EmployeesWithLwopReportService $service;

    private int $vlTypeId;

    private int $slTypeId;

    private int $elTypeId;

    private int $llTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb_new' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb_new');
        DB::connection('kdtphdb_new')->setPdo(DB::connection()->getPdo());

        $this->createSchema();

        Employee::query()->insert([
            ['id' => 1, 'firstname' => 'Ada', 'surname' => 'Admin', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 801, 'firstname' => 'Alex', 'surname' => 'Employee', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 808, 'firstname' => 'Taylor', 'surname' => 'Cruz', 'emp_status' => 1, 'group_id' => 20],
            ['id' => 810, 'firstname' => 'Long', 'surname' => 'Leave', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 811, 'firstname' => 'Pending', 'surname' => 'File', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 812, 'firstname' => 'Pat', 'surname' => 'Conversion', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 813, 'firstname' => 'Sam', 'surname' => 'Sick', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 814, 'firstname' => 'Eli', 'surname' => 'Converted', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 815, 'firstname' => 'Prior', 'surname' => 'Year', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 820, 'firstname' => 'No', 'surname' => 'Grant', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 99, 'firstname' => 'Inez', 'surname' => 'Inactive', 'emp_status' => 0, 'group_id' => 16],
        ]);

        DB::table('group_list')->insert([
            ['id' => 16, 'abbreviation' => 'SYS', 'name' => 'Systems', 'dept_id' => 1],
            ['id' => 20, 'abbreviation' => 'IT', 'name' => 'IT', 'dept_id' => 1],
        ]);

        $this->vlTypeId = (int) LeaveType::query()->insertGetId([
            'code' => 'VL',
            'type' => 'Vacation Leave',
        ]);
        $this->slTypeId = (int) LeaveType::query()->insertGetId([
            'code' => 'SL',
            'type' => 'Sick Leave',
        ]);
        $this->elTypeId = (int) LeaveType::query()->insertGetId([
            'code' => 'EL',
            'type' => 'Emergency Leave',
        ]);
        $this->llTypeId = (int) LeaveType::query()->insertGetId([
            'code' => 'LL',
            'type' => 'Long Leave',
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

        $this->service = new EmployeesWithLwopReportService($resolver);
    }

    public function test_lists_vl_sl_lwop_with_starting_balance_and_days_taken(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedEntitlement(801, $this->vlTypeId, '20.00', '2026-01-06 09:00:00');
        $this->seedEntitlement(801, $this->slTypeId, '18.00', '2026-01-06 09:00:00');

        $this->seedLeave(801, $this->vlTypeId, '15.00', '2.00', '2026-03-02', '2026-03-20', 'confirmed');
        $this->seedLeave(801, $this->slTypeId, '8.00', '1.00', '2026-04-06', '2026-04-16', 'confirmed');
        $this->seedLeave(801, $this->vlTypeId, '0.00', '1.00', '2026-11-02', '2026-11-02', 'confirmed');

        $this->seedLeave(808, $this->vlTypeId, '5.00', '0.00', '2026-03-02', '2026-03-06', 'confirmed');
        $this->seedLeave(810, $this->llTypeId, '0.00', '10.00', '2026-05-01', '2026-06-01', 'confirmed');
        $this->seedLeave(811, $this->vlTypeId, '0.00', '2.00', '2026-03-02', '2026-03-03', 'pending_approval');
        $this->seedLeave(812, $this->vlTypeId, '5.00', '0.50', '2026-06-01', '2026-06-08', 'pending_conversion');
        $this->seedLeave(813, $this->slTypeId, '3.00', '1.00', '2026-07-01', '2026-07-04', 'converted_to_sl');
        $this->seedLeave(814, $this->elTypeId, '5.00', '0.00', '2026-03-02', '2026-03-06', 'converted_to_el');
        $this->seedLeave(815, $this->vlTypeId, '0.00', '2.00', '2025-08-04', '2025-08-05', 'confirmed');
        $this->seedLeave(820, $this->vlTypeId, '0.00', '1.50', '2026-02-02', '2026-02-03', 'confirmed');
        $this->seedLeave(99, $this->vlTypeId, '0.00', '5.00', '2026-03-02', '2026-03-06', 'confirmed');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2026);

        $this->assertSame(2026, $report['year']);
        $this->assertSame('2026-09-04', $report['as_of']);
        $this->assertSame('2026-01-01', $report['period']['start_date']);
        $this->assertSame('2026-09-04', $report['period']['end_date']);
        $this->assertNull($report['group']);
        $this->assertSame(5, $report['employee_count']);

        $byId = collect($report['rows'])->keyBy(fn (array $row): int => $row['employee']['id']);

        $this->assertSame([801, 810, 812, 813, 820], $byId->keys()->all());

        $alex = $byId->get(801);
        $this->assertSame('20.00', $alex['vl_balance']);
        $this->assertSame('18.00', $alex['vl_consumed']);
        $this->assertSame('18.00', $alex['sl_balance']);
        $this->assertSame('9.00', $alex['sl_consumed']);
        $this->assertSame('0.00', $alex['ll_consumed']);
        $this->assertSame('4.00', $alex['total_lwop_days']);

        $longLeave = $byId->get(810);
        $this->assertSame('0.00', $longLeave['vl_consumed']);
        $this->assertSame('0.00', $longLeave['sl_consumed']);
        $this->assertSame('10.00', $longLeave['ll_consumed']);
        $this->assertSame('10.00', $longLeave['total_lwop_days']);

        $pat = $byId->get(812);
        $this->assertSame('0.00', $pat['vl_balance']);
        $this->assertSame('5.50', $pat['vl_consumed']);
        $this->assertSame('0.00', $pat['ll_consumed']);
        $this->assertSame('0.50', $pat['total_lwop_days']);

        $sam = $byId->get(813);
        $this->assertSame('0.00', $sam['vl_consumed']);
        $this->assertSame('4.00', $sam['sl_consumed']);
        $this->assertSame('0.00', $sam['ll_consumed']);
        $this->assertSame('1.00', $sam['total_lwop_days']);

        $noGrant = $byId->get(820);
        $this->assertSame('0.00', $noGrant['vl_balance']);
        $this->assertSame('1.50', $noGrant['vl_consumed']);
        $this->assertSame('0.00', $noGrant['ll_consumed']);
        $this->assertSame('1.50', $noGrant['total_lwop_days']);

        $this->assertSame([
            'vl_balance' => '20.00',
            'vl_consumed' => '25.00',
            'sl_balance' => '18.00',
            'sl_consumed' => '13.00',
            'll_consumed' => '10.00',
            'total_lwop_days' => '17.00',
        ], $report['grand_totals']);
    }

    public function test_current_year_label_is_ytd_but_future_accepted_leave_counts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(801, $this->vlTypeId, '0.00', '1.00', '2026-11-02', '2026-11-02', 'confirmed');
        $this->seedLeave(801, $this->vlTypeId, '0.00', '2.00', '2026-12-01', '2026-12-02', 'pending_approval');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2026);

        $this->assertSame('2026-01-01', $report['period']['start_date']);
        $this->assertSame('2026-09-04', $report['period']['end_date']);
        $this->assertSame(1, $report['employee_count']);
        $this->assertSame(801, $report['rows'][0]['employee']['id']);
        $this->assertSame('0.00', $report['rows'][0]['ll_consumed']);
        $this->assertSame('1.00', $report['rows'][0]['total_lwop_days']);
    }

    public function test_past_year_uses_full_calendar_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedEntitlement(815, $this->vlTypeId, '15.00', '2025-01-08 09:00:00');
        $this->seedLeave(815, $this->vlTypeId, '10.00', '2.00', '2025-08-04', '2025-08-18', 'confirmed');
        $this->seedLeave(815, $this->vlTypeId, '0.00', '1.00', '2025-11-10', '2025-11-10', 'confirmed');
        $this->seedLeave(815, $this->vlTypeId, '0.00', '4.00', '2026-02-02', '2026-02-05', 'confirmed');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2025);

        $this->assertSame('2025-12-31', $report['as_of']);
        $this->assertSame('2025-01-01', $report['period']['start_date']);
        $this->assertSame('2025-12-31', $report['period']['end_date']);
        $this->assertSame(1, $report['employee_count']);
        $this->assertSame(815, $report['rows'][0]['employee']['id']);
        $this->assertSame('15.00', $report['rows'][0]['vl_balance']);
        $this->assertSame('13.00', $report['rows'][0]['vl_consumed']);
        $this->assertSame('0.00', $report['rows'][0]['ll_consumed']);
        $this->assertSame('3.00', $report['rows'][0]['total_lwop_days']);
    }

    public function test_group_filter_limits_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(801, $this->vlTypeId, '0.00', '1.00', '2026-03-02', '2026-03-02', 'confirmed');
        $this->seedLeave(808, $this->vlTypeId, '0.00', '2.00', '2026-03-02', '2026-03-03', 'confirmed');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2026, 20);

        $this->assertSame([
            'id' => 20,
            'abbreviation' => 'IT',
            'name' => 'IT',
            'dept_id' => 1,
        ], $report['group']);
        $this->assertSame(1, $report['employee_count']);
        $this->assertSame(808, $report['rows'][0]['employee']['id']);
        $this->assertSame('0.00', $report['rows'][0]['ll_consumed']);
        $this->assertSame('2.00', $report['rows'][0]['total_lwop_days']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->andReturn(new ApproverContext(
            empId: 801,
            approvalLevel: null,
            groupCodes: [],
            isPresident: false,
            isAdmin: false,
        ));

        $service = new EmployeesWithLwopReportService($resolver);

        $this->expectException(AuthorizationException::class);

        $service->forAdmin(Employee::query()->findOrFail(801), 2026);
    }

    public function test_for_employee_splits_vl_and_sl_lwop_including_future_confirmed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(801, $this->vlTypeId, '15.00', '2.00', '2026-03-02', '2026-03-20', 'confirmed');
        $this->seedLeave(801, $this->slTypeId, '8.00', '1.50', '2026-04-06', '2026-04-16', 'confirmed');
        $this->seedLeave(801, $this->vlTypeId, '0.00', '1.00', '2026-11-02', '2026-11-02', 'confirmed');
        $this->seedLeave(801, $this->vlTypeId, '0.00', '2.00', '2026-12-01', '2026-12-02', 'pending_approval');
        $this->seedLeave(801, $this->llTypeId, '0.00', '10.00', '2026-05-01', '2026-06-01', 'confirmed');
        $this->seedLeave(801, $this->elTypeId, '5.00', '0.00', '2026-03-02', '2026-03-06', 'converted_to_el');
        $this->seedLeave(801, $this->vlTypeId, '0.00', '4.00', '2025-08-04', '2025-08-05', 'confirmed');

        $summary = $this->service->forEmployee(Employee::query()->findOrFail(801), 2026);

        $this->assertSame([
            'year' => 2026,
            'vl_lwop_days' => '3.00',
            'sl_lwop_days' => '1.50',
            'll_lwop_days' => '10.00',
            'total_lwop_days' => '14.50',
        ], $summary);
    }

    public function test_for_employee_returns_zeros_when_there_is_no_lwop(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(801, $this->vlTypeId, '2.00', '0.00', '2026-03-02', '2026-03-03', 'confirmed');

        $summary = $this->service->forEmployee(Employee::query()->findOrFail(801), 2026);

        $this->assertSame([
            'year' => 2026,
            'vl_lwop_days' => '0.00',
            'sl_lwop_days' => '0.00',
            'll_lwop_days' => '0.00',
            'total_lwop_days' => '0.00',
        ], $summary);
    }

    public function test_for_employee_counts_pending_conversion_as_vl_and_converted_to_sl_as_sl(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(812, $this->vlTypeId, '5.00', '0.50', '2026-06-01', '2026-06-08', 'pending_conversion');
        $this->seedLeave(813, $this->slTypeId, '3.00', '1.00', '2026-07-01', '2026-07-04', 'converted_to_sl');

        $pat = $this->service->forEmployee(Employee::query()->findOrFail(812), 2026);
        $sam = $this->service->forEmployee(Employee::query()->findOrFail(813), 2026);

        $this->assertSame('0.50', $pat['vl_lwop_days']);
        $this->assertSame('0.00', $pat['sl_lwop_days']);
        $this->assertSame('0.00', $pat['ll_lwop_days']);
        $this->assertSame('0.50', $pat['total_lwop_days']);

        $this->assertSame('0.00', $sam['vl_lwop_days']);
        $this->assertSame('1.00', $sam['sl_lwop_days']);
        $this->assertSame('0.00', $sam['ll_lwop_days']);
        $this->assertSame('1.00', $sam['total_lwop_days']);
    }

    public function test_for_employee_past_year_omits_other_years(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(815, $this->vlTypeId, '10.00', '2.00', '2025-08-04', '2025-08-18', 'confirmed');
        $this->seedLeave(815, $this->vlTypeId, '0.00', '1.00', '2025-11-10', '2025-11-10', 'confirmed');
        $this->seedLeave(815, $this->vlTypeId, '0.00', '4.00', '2026-02-02', '2026-02-05', 'confirmed');

        $summary = $this->service->forEmployee(Employee::query()->findOrFail(815), 2025);

        $this->assertSame([
            'year' => 2025,
            'vl_lwop_days' => '3.00',
            'sl_lwop_days' => '0.00',
            'll_lwop_days' => '0.00',
            'total_lwop_days' => '3.00',
        ], $summary);
    }

    public function test_for_employee_includes_confirmed_ll_and_omits_pending_ll(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:23:00', 'Asia/Manila'));

        $this->seedLeave(810, $this->llTypeId, '0.00', '31.00', '2026-05-01', '2026-05-31', 'confirmed');
        $this->seedLeave(810, $this->llTypeId, '0.00', '45.00', '2026-07-01', '2026-08-14', 'pending_approval');
        $this->seedLeave(810, $this->llTypeId, '0.00', '20.00', '2025-11-01', '2025-11-20', 'confirmed');

        $summary = $this->service->forEmployee(Employee::query()->findOrFail(810), 2026);

        $this->assertSame([
            'year' => 2026,
            'vl_lwop_days' => '0.00',
            'sl_lwop_days' => '0.00',
            'll_lwop_days' => '31.00',
            'total_lwop_days' => '31.00',
        ], $summary);
    }

    private function seedEntitlement(int $empId, int $leaveTypeId, string $availableAfter, string $manilaCreatedAt): void
    {
        $createdAt = CarbonImmutable::parse($manilaCreatedAt, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');

        DB::table('leave_balance_transaction')->insert([
            'emp_id' => $empId,
            'leave_id' => null,
            'leave_type_id' => $leaveTypeId,
            'available_delta' => '15.00',
            'reserved_delta' => '0.00',
            'available_after' => $availableAfter,
            'reserved_after' => '0.00',
            'reason' => 'annual_entitlement',
            'remarks' => 'Annual entitlement 2026',
            'created_at' => $createdAt,
        ]);
    }

    private function seedLeave(
        int $empId,
        int $leaveTypeId,
        string $paidDays,
        string $lwopDays,
        string $startDate,
        string $endDate,
        string $status,
    ): void {
        $duration = bcadd($paidDays, $lwopDays, 2);

        LeaveInfo::query()->create([
            'emp_id' => $empId,
            'filed_by_emp_id' => $empId,
            'leave_type_id' => $leaveTypeId,
            'duration' => $duration,
            'paid_days' => $paidDays,
            'lwop_days' => $lwopDays,
            'day_type' => 'full',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'leave_reason' => 'test',
            'status' => $status,
            'confirmed_at' => in_array($status, ['pending_approval'], true) ? null : '2026-01-15 08:00:00',
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_balance_transaction');
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
            $table->string('status', 30);
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_balance_transaction', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->unsignedBigInteger('leave_id')->nullable();
            $table->unsignedBigInteger('leave_type_id');
            $table->decimal('available_delta', 8, 2);
            $table->decimal('reserved_delta', 8, 2);
            $table->decimal('available_after', 8, 2);
            $table->decimal('reserved_after', 8, 2);
            $table->string('reason', 40);
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
