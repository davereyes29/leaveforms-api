<?php

namespace Tests\Unit;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\BalanceTransactionReason;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveActivityListService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveActivityListServiceTest extends TestCase
{
    private LeaveActivityListService $service;

    private LeaveType $vl;

    private LeaveType $sl;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb_new' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb_new');
        DB::connection('kdtphdb_new')->setPdo(DB::connection()->getPdo());

        $this->createSchema();

        $this->vl = LeaveType::query()->create(['code' => 'VL', 'type' => 'Vacation Leave']);
        $this->sl = LeaveType::query()->create(['code' => 'SL', 'type' => 'Sick Leave']);

        Employee::query()->insert([
            ['id' => 8, 'firstname' => 'Alex', 'surname' => 'Employee'],
            ['id' => 9, 'firstname' => 'Sam', 'surname' => 'Rivera'],
            ['id' => 12, 'firstname' => 'Ariane', 'surname' => 'Derigay'],
        ]);

        $this->service = new LeaveActivityListService;
    }

    public function test_includes_batch_credit_and_manual_adjust_for_the_viewer_only(): void
    {
        $batch = LeaveActivityHistory::recordBatchCreditDistribution(
            12,
            ActivityType::AnnualCreditsDistributed,
            (int) $this->vl->id,
            [8, 9],
            'Annual entitlement 2026',
        );

        LeaveActivityHistory::recordBatchCreditDistribution(
            12,
            ActivityType::AnnualCreditsDistributed,
            (int) $this->vl->id,
            [9],
            'Annual entitlement 2026 (other only)',
        );

        $adjust = LeaveActivityHistory::recordSoloCreditAdjustment(
            12,
            8,
            (int) $this->sl->id,
            'Opening balance overstated',
        );

        LeaveActivityHistory::recordSoloCreditAdjustment(
            12,
            9,
            (int) $this->sl->id,
            'Someone else',
        );

        $this->ledger(8, (int) $this->vl->id, BalanceTransactionReason::AnnualEntitlement, '15.00');
        $this->ledger(9, (int) $this->vl->id, BalanceTransactionReason::AnnualEntitlement, '15.00');
        $this->ledger(8, (int) $this->sl->id, BalanceTransactionReason::ManualAdjustment, '-2.00');
        $this->ledger(9, (int) $this->sl->id, BalanceTransactionReason::ManualAdjustment, '1.00');

        $page = $this->service->paginateForEmployee(8);

        $types = $page->getCollection()->pluck('activity_type')->map->value->all();

        $this->assertEqualsCanonicalizing(
            ['annual_credits_distributed', 'credits_adjusted'],
            $types,
        );
        $this->assertSame(2, $page->total());

        $byId = $page->getCollection()->keyBy('id');

        $this->assertSame('15.0 VL credited', $byId[$batch->id]->balance_impact);
        $this->assertSame('annual_entitlement', $byId[$batch->id]->ledger[0]->reason->value);
        $this->assertSame('15.00', $byId[$batch->id]->ledger[0]->available_delta);
        $this->assertSame('2.0 SL deducted', $byId[$adjust->id]->balance_impact);
        $this->assertSame('Ariane Derigay', $byId[$batch->id]->performed_by_name);
    }

    public function test_code_filter_applies_to_credit_rows(): void
    {
        LeaveActivityHistory::recordBatchCreditDistribution(
            12,
            ActivityType::AnnualCreditsDistributed,
            (int) $this->vl->id,
            [8],
            'Annual entitlement 2026',
        );
        LeaveActivityHistory::recordSoloCreditAdjustment(
            12,
            8,
            (int) $this->sl->id,
            'Correction',
        );
        $this->ledger(8, (int) $this->vl->id, BalanceTransactionReason::AnnualEntitlement, '15.00');
        $this->ledger(8, (int) $this->sl->id, BalanceTransactionReason::ManualAdjustment, '-2.00');

        $vlOnly = $this->service->paginateForEmployee(8, 'VL');
        $slOnly = $this->service->paginateForEmployee(8, 'SL');

        $this->assertSame(['annual_credits_distributed'], $vlOnly->getCollection()->pluck('activity_type')->map->value->all());
        $this->assertSame(['credits_adjusted'], $slOnly->getCollection()->pluck('activity_type')->map->value->all());
    }

    public function test_still_includes_the_viewers_leave_lifecycle_rows(): void
    {
        $ownLeave = LeaveInfo::query()->create([
            'emp_id' => 8,
            'filed_by_emp_id' => 8,
            'leave_type_id' => $this->vl->id,
            'duration' => '1.00',
            'paid_days' => '1.00',
            'lwop_days' => '0.00',
            'day_type' => 'full',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'leave_reason' => 'Vacation',
            'status' => 'pending_approval',
        ]);
        LeaveInfo::query()->create([
            'emp_id' => 9,
            'filed_by_emp_id' => 9,
            'leave_type_id' => $this->vl->id,
            'duration' => '1.00',
            'paid_days' => '1.00',
            'lwop_days' => '0.00',
            'day_type' => 'full',
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-02',
            'leave_reason' => 'Other',
            'status' => 'pending_approval',
        ]);

        LeaveActivityHistory::query()->create([
            'leave_id' => $ownLeave->id,
            'performed_by_emp_id' => 8,
            'activity_type' => ActivityType::Submitted,
        ]);

        $page = $this->service->paginateForEmployee(8);

        $this->assertSame(1, $page->total());
        $this->assertSame('submitted', $page->getCollection()->first()->activity_type->value);
        $this->assertSame($ownLeave->id, $page->getCollection()->first()->leave_id);
    }

    private function ledger(int $empId, int $leaveTypeId, BalanceTransactionReason $reason, string $availableDelta): void
    {
        LeaveBalanceTransaction::query()->create([
            'emp_id' => $empId,
            'leave_id' => null,
            'leave_type_id' => $leaveTypeId,
            'available_delta' => $availableDelta,
            'reserved_delta' => '0.00',
            'available_after' => '0.00',
            'reserved_after' => '0.00',
            'reason' => $reason,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_activity_recipient');
        Schema::dropIfExists('leave_balance_transaction');
        Schema::dropIfExists('leave_activity_history');
        Schema::dropIfExists('leave_conversion');
        Schema::dropIfExists('leave_info');
        Schema::dropIfExists('leave_type');
        Schema::dropIfExists('employee_list');

        Schema::create('leave_type', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('type', 100);
        });

        Schema::create('leave_conversion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('to_leave_type_id')->nullable();
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

        Schema::create('employee_list', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('firstname');
            $table->string('surname');
        });
    }
}
