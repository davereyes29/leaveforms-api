<?php

namespace Tests\Unit;

use App\Enums\Leave\BalanceTransactionReason;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveReportsDemoSeedService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class LeaveReportsDemoSeedServiceTest extends TestCase
{
    private LeaveReportsDemoSeedService $service;

    private LeaveType $vl;

    private LeaveType $sl;

    /** @var array<int, Employee> */
    private array $employees;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        $this->vl = LeaveType::query()->create(['code' => 'VL', 'type' => 'Vacation Leave']);
        $this->sl = LeaveType::query()->create(['code' => 'SL', 'type' => 'Sick Leave']);

        $this->employees = [];

        foreach ([510, 517, 541, 542, 543] as $empId) {
            $employee = new Employee;
            $employee->id = $empId;
            $employee->firstname = 'Demo';
            $employee->surname = (string) $empId;
            $this->employees[$empId] = $employee;
        }

        $this->service = new LeaveReportsDemoSeedService(new LeaveBalanceService);
    }

    public function test_seeds_monetization_balances_above_retain_floors(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00', 'Asia/Manila'));

        $summary = $this->service->seed($this->employees);

        $this->assertSame(2026, $summary['year']);
        $this->assertSame([517, 542, 543], array_column($summary['monetization'], 'emp_id'));

        $this->assertSame('15.00', $this->available(517, (int) $this->vl->id));
        $this->assertSame('32.00', $this->available(517, (int) $this->sl->id));
        $this->assertSame('14.00', $this->available(542, (int) $this->vl->id));
        $this->assertSame('20.00', $this->available(542, (int) $this->sl->id));
        $this->assertSame('8.00', $this->available(543, (int) $this->vl->id));
        $this->assertSame('35.00', $this->available(543, (int) $this->sl->id));

        $jan6 = CarbonImmutable::parse('2026-01-06 09:00:00', 'Asia/Manila')->utc()->format('Y-m-d H:i:s');

        $this->assertSame($jan6, $this->latestLedgerCreatedAt(517, (int) $this->vl->id));
        $this->assertSame($jan6, $this->latestLedgerCreatedAt(543, (int) $this->sl->id));
    }

    public function test_seeds_lwop_leaves_and_annual_entitlement_without_monetization_overlap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00', 'Asia/Manila'));

        $summary = $this->service->seed($this->employees);

        $this->assertSame([510, 541], array_column($summary['lwop'], 'emp_id'));
        $this->assertSame('2.00', $summary['lwop'][0]['total_lwop_days']);
        $this->assertSame('3.00', $summary['lwop'][1]['total_lwop_days']);

        $leave510 = LeaveInfo::query()->where('emp_id', 510)->firstOrFail();
        $this->assertSame((int) $this->vl->id, (int) $leave510->leave_type_id);
        $this->assertSame(LeaveStatus::Confirmed, $leave510->status);
        $this->assertSame('15.00', bcadd((string) $leave510->paid_days, '0', 2));
        $this->assertSame('2.00', bcadd((string) $leave510->lwop_days, '0', 2));
        $this->assertSame('2026-03-02', $leave510->start_date->toDateString());
        $this->assertStringStartsWith(LeaveReportsDemoSeedService::REASON_PREFIX, $leave510->leave_reason);

        $leave541 = LeaveInfo::query()->where('emp_id', 541)->firstOrFail();
        $this->assertSame((int) $this->sl->id, (int) $leave541->leave_type_id);
        $this->assertSame('18.00', bcadd((string) $leave541->paid_days, '0', 2));
        $this->assertSame('3.00', bcadd((string) $leave541->lwop_days, '0', 2));
        $this->assertSame('2026-04-06', $leave541->start_date->toDateString());

        $this->assertSame('0.00', $this->available(510, (int) $this->vl->id));
        $this->assertSame('18.00', $this->available(510, (int) $this->sl->id));
        $this->assertSame('10.00', $this->available(541, (int) $this->vl->id));
        $this->assertSame('0.00', $this->available(541, (int) $this->sl->id));

        $this->assertTrue(bccomp($this->available(510, (int) $this->vl->id), '10.00', 2) <= 0);
        $this->assertTrue(bccomp($this->available(510, (int) $this->sl->id), '30.00', 2) <= 0);
        $this->assertTrue(bccomp($this->available(541, (int) $this->vl->id), '10.00', 2) <= 0);
        $this->assertTrue(bccomp($this->available(541, (int) $this->sl->id), '30.00', 2) <= 0);

        $this->assertSame('15.00', $this->entitlementAfter(510, (int) $this->vl->id));
        $this->assertSame('18.00', $this->entitlementAfter(510, (int) $this->sl->id));
        $this->assertSame('15.00', $this->entitlementAfter(541, (int) $this->vl->id));
        $this->assertSame('18.00', $this->entitlementAfter(541, (int) $this->sl->id));
    }

    public function test_re_run_replaces_demo_leaves_and_does_not_double_entitlement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00', 'Asia/Manila'));

        $this->service->seed($this->employees);
        $summary = $this->service->seed($this->employees);

        $this->assertSame(1, LeaveInfo::query()->where('emp_id', 510)->count());
        $this->assertSame(1, LeaveInfo::query()->where('emp_id', 541)->count());
        $this->assertSame(0, LeaveInfo::query()->whereIn('emp_id', [517, 542, 543])->count());

        $this->assertSame(1, $this->entitlementCount(510, (int) $this->vl->id));
        $this->assertSame(1, $this->entitlementCount(510, (int) $this->sl->id));
        $this->assertSame(1, $this->entitlementCount(541, (int) $this->vl->id));
        $this->assertSame(1, $this->entitlementCount(541, (int) $this->sl->id));

        $this->assertSame('15.00', $this->available(517, (int) $this->vl->id));
        $this->assertSame('0.00', $this->available(510, (int) $this->vl->id));
        $this->assertSame('2.00', $summary['lwop'][0]['total_lwop_days']);
    }

    public function test_rejects_missing_employee(): void
    {
        unset($this->employees[517]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Employee 517 is required');

        $this->service->seed($this->employees);
    }

    private function available(int $empId, int $leaveTypeId): string
    {
        $value = LeaveBalance::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->value('available');

        return $value === null ? '0.00' : bcadd((string) $value, '0', 2);
    }

    private function latestLedgerCreatedAt(int $empId, int $leaveTypeId): ?string
    {
        $createdAt = LeaveBalanceTransaction::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->orderByDesc('id')
            ->value('created_at');

        if ($createdAt === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $createdAt)->utc()->format('Y-m-d H:i:s');
    }

    private function entitlementAfter(int $empId, int $leaveTypeId): string
    {
        $value = LeaveBalanceTransaction::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('available_after');

        return $value === null ? '0.00' : bcadd((string) $value, '0', 2);
    }

    private function entitlementCount(int $empId, int $leaveTypeId): int
    {
        return LeaveBalanceTransaction::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->count();
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_balance_transaction');
        Schema::dropIfExists('leave_balance');
        Schema::dropIfExists('leave_info');
        Schema::dropIfExists('leave_type');

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
