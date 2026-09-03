<?php

namespace Tests\Unit;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveType;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveMonetizationCandidatesService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class LeaveMonetizationCandidatesServiceTest extends TestCase
{
    private LeaveMonetizationCandidatesService $service;

    private int $vlTypeId;

    private int $slTypeId;

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
            ['id' => 810, 'firstname' => 'Cecile', 'surname' => 'Santos', 'emp_status' => 1, 'group_id' => 16],
            ['id' => 820, 'firstname' => 'Below', 'surname' => 'Threshold', 'emp_status' => 1, 'group_id' => 16],
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

        $this->service = new LeaveMonetizationCandidatesService($resolver);
    }

    public function test_live_balances_before_cut_off_include_vl_sl_and_both(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03 20:45:00', 'Asia/Manila'));

        $this->seedLiveBalance(801, $this->vlTypeId, '15.00');
        $this->seedLiveBalance(801, $this->slTypeId, '32.00');
        $this->seedLiveBalance(808, $this->vlTypeId, '14.00');
        $this->seedLiveBalance(808, $this->slTypeId, '20.00');
        $this->seedLiveBalance(810, $this->vlTypeId, '8.00');
        $this->seedLiveBalance(810, $this->slTypeId, '35.00');
        $this->seedLiveBalance(820, $this->vlTypeId, '10.00');
        $this->seedLiveBalance(820, $this->slTypeId, '30.00');
        $this->seedLiveBalance(99, $this->vlTypeId, '50.00');
        $this->seedLiveBalance(99, $this->slTypeId, '50.00');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2026);

        $this->assertSame(2026, $report['conversion_year']);
        $this->assertSame('2026-11-30', $report['balance_cut_off']);
        $this->assertSame('2026-09-03', $report['as_of']);
        $this->assertSame(3, $report['eligible_employees']);

        $byId = collect($report['rows'])->keyBy(fn (array $row): int => $row['employee']['id']);

        $this->assertSame([801, 808, 810], $byId->keys()->all());

        $alex = $byId->get(801);
        $this->assertSame('15.00', $alex['vl_balance']);
        $this->assertSame('10.00', $alex['vl_retained']);
        $this->assertSame('5.00', $alex['vl_excess']);
        $this->assertSame('32.00', $alex['sl_balance']);
        $this->assertSame('30.00', $alex['sl_retained']);
        $this->assertSame('2.00', $alex['sl_excess']);
        $this->assertSame('7.00', $alex['total_eligible_days']);
        $this->assertSame('VL and SL', $alex['eligibility_basis']);

        $taylor = $byId->get(808);
        $this->assertSame('14.00', $taylor['vl_balance']);
        $this->assertSame('4.00', $taylor['vl_excess']);
        $this->assertNull($taylor['sl_balance']);
        $this->assertNull($taylor['sl_retained']);
        $this->assertNull($taylor['sl_excess']);
        $this->assertSame('4.00', $taylor['total_eligible_days']);
        $this->assertSame('VL', $taylor['eligibility_basis']);

        $cecile = $byId->get(810);
        $this->assertNull($cecile['vl_balance']);
        $this->assertNull($cecile['vl_retained']);
        $this->assertNull($cecile['vl_excess']);
        $this->assertSame('35.00', $cecile['sl_balance']);
        $this->assertSame('5.00', $cecile['sl_excess']);
        $this->assertSame('5.00', $cecile['total_eligible_days']);
        $this->assertSame('SL', $cecile['eligibility_basis']);

        $this->assertSame([
            'vl_balance' => '29.00',
            'vl_excess_days' => '9.00',
            'sl_balance' => '67.00',
            'sl_excess_days' => '7.00',
            'total_eligible_days' => '16.00',
        ], $report['grand_totals']);
    }

    public function test_after_cut_off_uses_ledger_as_of_november_30(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-07 10:00:00', 'Asia/Manila'));

        $this->seedLiveBalance(801, $this->vlTypeId, '40.00');
        $this->seedLiveBalance(801, $this->slTypeId, '50.00');

        $this->seedLedger(801, $this->vlTypeId, '15.00', '2026-11-30 08:00:00');
        $this->seedLedger(801, $this->slTypeId, '32.00', '2026-11-30 08:00:00');
        $this->seedLedger(801, $this->vlTypeId, '40.00', '2026-12-01 09:00:00');
        $this->seedLedger(801, $this->slTypeId, '50.00', '2026-12-01 09:00:00');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2026);

        $this->assertSame('2026-11-30', $report['as_of']);
        $this->assertSame(1, $report['eligible_employees']);

        $alex = $report['rows'][0];
        $this->assertSame(801, $alex['employee']['id']);
        $this->assertSame('15.00', $alex['vl_balance']);
        $this->assertSame('5.00', $alex['vl_excess']);
        $this->assertSame('32.00', $alex['sl_balance']);
        $this->assertSame('2.00', $alex['sl_excess']);
        $this->assertSame('7.00', $alex['total_eligible_days']);
    }

    public function test_past_conversion_year_freezes_at_that_years_cut_off(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03 20:45:00', 'Asia/Manila'));

        $this->seedLiveBalance(801, $this->vlTypeId, '99.00');
        $this->seedLiveBalance(801, $this->slTypeId, '99.00');

        $this->seedLedger(801, $this->vlTypeId, '18.00', '2025-11-30 12:00:00');
        $this->seedLedger(801, $this->slTypeId, '33.00', '2025-11-30 12:00:00');
        $this->seedLedger(801, $this->vlTypeId, '99.00', '2026-01-02 09:00:00');

        $report = $this->service->forAdmin(Employee::query()->findOrFail(1), 2025);

        $this->assertSame('2025-11-30', $report['balance_cut_off']);
        $this->assertSame('2025-11-30', $report['as_of']);
        $this->assertSame('18.00', $report['rows'][0]['vl_balance']);
        $this->assertSame('8.00', $report['rows'][0]['vl_excess']);
        $this->assertSame('33.00', $report['rows'][0]['sl_balance']);
        $this->assertSame('3.00', $report['rows'][0]['sl_excess']);
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

        $service = new LeaveMonetizationCandidatesService($resolver);

        $this->expectException(AuthorizationException::class);

        $service->forAdmin(Employee::query()->findOrFail(801), 2026);
    }

    private function seedLiveBalance(int $empId, int $leaveTypeId, string $available): void
    {
        LeaveBalance::query()->create([
            'emp_id' => $empId,
            'leave_type_id' => $leaveTypeId,
            'available' => $available,
            'reserved' => '0.00',
        ]);
    }

    private function seedLedger(int $empId, int $leaveTypeId, string $availableAfter, string $manilaCreatedAt): void
    {
        $createdAt = CarbonImmutable::parse($manilaCreatedAt, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');

        // Insert via the query builder so SQLite keeps our created_at (Eloquent
        // + useCurrent() was falling back to CURRENT_TIMESTAMP in tests).
        DB::table('leave_balance_transaction')->insert([
            'emp_id' => $empId,
            'leave_id' => null,
            'leave_type_id' => $leaveTypeId,
            'available_delta' => '0.00',
            'reserved_delta' => '0.00',
            'available_after' => $availableAfter,
            'reserved_after' => '0.00',
            'reason' => 'manual_adjustment',
            'remarks' => 'test',
            'created_at' => $createdAt,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_balance_transaction');
        Schema::dropIfExists('leave_balance');
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

        Schema::create('leave_balance', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->decimal('available', 8, 2)->default(0);
            $table->decimal('reserved', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['emp_id', 'leave_type_id']);
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
