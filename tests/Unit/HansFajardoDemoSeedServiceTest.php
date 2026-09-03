<?php

namespace Tests\Unit;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\HansFajardoDemoSeedService;
use App\Services\Leave\LeaveApprovalPolicy;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\LeaveFinalizationService;
use App\Services\Leave\LeaveOverlapValidator;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HansFajardoDemoSeedServiceTest extends TestCase
{
    private HansFajardoDemoSeedService $service;

    private LeaveType $vl;

    private LeaveType $sl;

    private LeaveType $el;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        $this->vl = LeaveType::query()->create(['code' => 'VL', 'type' => 'Vacation Leave']);
        $this->sl = LeaveType::query()->create(['code' => 'SL', 'type' => 'Sick Leave']);
        $this->el = LeaveType::query()->create(['code' => 'EL', 'type' => 'Emergency Leave']);

        $this->employee = new Employee;
        $this->employee->id = HansFajardoDemoSeedService::EMP_ID;
        $this->employee->firstname = 'Hans';
        $this->employee->surname = 'Fajardo';

        $duration = Mockery::mock(LeaveDurationService::class);
        $duration->shouldReceive('isWorkday')->andReturn(true);

        $policy = Mockery::mock(LeaveApprovalPolicy::class);
        $policy->shouldReceive('requirementsFor')->andReturn([
            [
                'display_order' => 1,
                'allowed_levels' => [1, 2, 3, 4],
                'required_approver_type' => null,
            ],
        ]);

        $balance = new LeaveBalanceService;
        $this->service = new HansFajardoDemoSeedService(
            $balance,
            $policy,
            new LeaveFinalizationService($balance),
            $duration,
            new LeaveOverlapValidator,
        );
    }

    public function test_leaves_leftover_available_at_five_after_filing_demo_vls(): void
    {
        $summary = $this->service->seed($this->employee, $this->employee);

        $this->assertSame('5.00', $summary['vl_available']);
        $this->assertSame('2.00', $summary['vl_reserved']);
        $this->assertSame('5.00', $summary['sl_available']);
        $this->assertSame('5.00', $summary['el_available']);
        $this->assertSame(5, $summary['leaves']);

        $leaves = LeaveInfo::query()
            ->where('emp_id', HansFajardoDemoSeedService::EMP_ID)
            ->orderBy('start_date')
            ->get();

        $this->assertCount(5, $leaves);
        $this->assertSame('2026-08-28', $leaves[0]->start_date->toDateString());
        $this->assertSame(LeaveStatus::Confirmed, $leaves[0]->status);
        $this->assertSame('2026-09-01', $leaves[1]->start_date->toDateString());
        $this->assertSame(LeaveStatus::PendingApproval, $leaves[1]->status);
        $this->assertSame('2026-09-02', $leaves[2]->start_date->toDateString());
        $this->assertSame(LeaveStatus::PendingApproval, $leaves[2]->status);
        $this->assertSame('2026-09-03', $leaves[3]->start_date->toDateString());
        $this->assertSame(LeaveStatus::Confirmed, $leaves[3]->status);
        $this->assertSame('2026-09-04', $leaves[4]->start_date->toDateString());
        $this->assertSame(LeaveStatus::Confirmed, $leaves[4]->status);

        foreach ($leaves as $leave) {
            $this->assertSame((int) $this->vl->id, (int) $leave->leave_type_id);
            $this->assertSame('1.00', (string) $leave->duration);
            $this->assertStringStartsWith(HansFajardoDemoSeedService::REASON_PREFIX, $leave->leave_reason);
        }
    }

    public function test_trims_existing_credits_so_leftover_available_is_five(): void
    {
        LeaveBalance::query()->create([
            'emp_id' => HansFajardoDemoSeedService::EMP_ID,
            'leave_type_id' => $this->vl->id,
            'available' => '20.00',
            'reserved' => '0.00',
        ]);
        LeaveBalance::query()->create([
            'emp_id' => HansFajardoDemoSeedService::EMP_ID,
            'leave_type_id' => $this->sl->id,
            'available' => '8.00',
            'reserved' => '0.00',
        ]);
        LeaveBalance::query()->create([
            'emp_id' => HansFajardoDemoSeedService::EMP_ID,
            'leave_type_id' => $this->el->id,
            'available' => '1.00',
            'reserved' => '0.00',
        ]);

        $summary = $this->service->seed($this->employee, $this->employee);

        $this->assertSame('5.00', $summary['vl_available']);
        $this->assertSame('2.00', $summary['vl_reserved']);
        $this->assertSame('5.00', $summary['sl_available']);
        $this->assertSame('5.00', $summary['el_available']);
    }

    public function test_re_run_replaces_demo_leaves_and_keeps_target_balances(): void
    {
        $this->service->seed($this->employee, $this->employee);
        $summary = $this->service->seed($this->employee, $this->employee);

        $this->assertSame(5, LeaveInfo::query()->where('emp_id', HansFajardoDemoSeedService::EMP_ID)->count());
        $this->assertSame('5.00', $summary['vl_available']);
        $this->assertSame('2.00', $summary['vl_reserved']);
        $this->assertSame('5.00', $summary['sl_available']);
        $this->assertSame('5.00', $summary['el_available']);
    }

    public function test_rejects_a_non_workday_scenario_date(): void
    {
        $duration = Mockery::mock(LeaveDurationService::class);
        $duration->shouldReceive('isWorkday')->andReturnUsing(
            fn (Carbon $date): bool => $date->toDateString() !== '2026-08-28',
        );

        $policy = Mockery::mock(LeaveApprovalPolicy::class);
        $balance = new LeaveBalanceService;
        $service = new HansFajardoDemoSeedService(
            $balance,
            $policy,
            new LeaveFinalizationService($balance),
            $duration,
            new LeaveOverlapValidator,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026-08-28 is not a workday');

        $service->seed($this->employee, $this->employee);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_activity_recipient');
        Schema::dropIfExists('leave_balance_transaction');
        Schema::dropIfExists('leave_approval_history');
        Schema::dropIfExists('leave_approval_requirement');
        Schema::dropIfExists('leave_conversion_attachment');
        Schema::dropIfExists('leave_info_attachment');
        Schema::dropIfExists('leave_conversion');
        Schema::dropIfExists('leave_activity_history');
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

        Schema::create('leave_approval_requirement', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedTinyInteger('display_order');
            $table->json('allowed_levels')->nullable();
            $table->string('required_approver_type', 20)->nullable();
            $table->boolean('is_satisfied')->default(false);
            $table->dateTime('satisfied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_approval_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('approver_emp_id');
            $table->unsignedTinyInteger('approval_level')->nullable();
            $table->string('approver_type', 20);
            $table->boolean('is_approved');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('leave_activity_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id')->nullable();
            $table->unsignedBigInteger('subject_emp_id')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->unsignedBigInteger('performed_by_emp_id');
            $table->string('activity_type', 50);
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('leave_activity_recipient', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('emp_id');
        });

        Schema::create('leave_conversion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('from_leave_type_id');
            $table->unsignedBigInteger('to_leave_type_id');
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::create('leave_info_attachment', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('leave_conversion_attachment', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversion_id');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
}
