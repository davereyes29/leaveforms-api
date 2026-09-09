<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\VacationLeaveQuarterUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VacationLeaveQuarterUsageServiceTest extends TestCase
{
    private VacationLeaveQuarterUsageService $service;

    private int $vlTypeId;

    private int $slTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.kdtphdb' => config('database.connections.sqlite')]);
        DB::purge('kdtphdb');
        DB::connection('kdtphdb')->setPdo(DB::connection()->getPdo());

        $this->createSchema();

        $this->vlTypeId = (int) DB::table('leave_type')->insertGetId([
            'code' => 'VL',
            'type' => 'Vacation Leave',
        ]);
        $this->slTypeId = (int) DB::table('leave_type')->insertGetId([
            'code' => 'SL',
            'type' => 'Sick Leave',
        ]);

        $this->service = new VacationLeaveQuarterUsageService(new LeaveDurationService);
    }

    public function test_non_vl_is_not_applicable(): void
    {
        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'SL');

        $this->assertFalse($usage['applicable']);
        $this->assertSame('ok', $usage['level']);
        $this->assertSame('9.00', $usage['cap']);
        $this->assertSame([], $usage['quarters']);
    }

    public function test_eight_confirmed_plus_one_day_is_at_cap(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'VL');

        $this->assertTrue($usage['applicable']);
        $this->assertSame('2026-Q3', $usage['quarter']);
        $this->assertSame('8.00', $usage['taken']);
        $this->assertSame('1.00', $usage['this_request']);
        $this->assertSame('9.00', $usage['projected']);
        $this->assertSame('at_cap', $usage['level']);
    }

    public function test_eight_confirmed_plus_two_days_exceeds(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-15', DayType::Full, 'VL');

        $this->assertSame('8.00', $usage['taken']);
        $this->assertSame('2.00', $usage['this_request']);
        $this->assertSame('10.00', $usage['projected']);
        $this->assertSame('exceeded', $usage['level']);
    }

    public function test_half_day_request_can_land_between_cap_and_exceeded(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::HalfAm, 'VL');

        $this->assertSame('8.00', $usage['taken']);
        $this->assertSame('0.50', $usage['this_request']);
        $this->assertSame('8.50', $usage['projected']);
        $this->assertSame('ok', $usage['level']);
    }

    public function test_pending_vl_does_not_count_as_taken(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'pending_approval');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-15', DayType::Full, 'VL');

        $this->assertSame('0.00', $usage['taken']);
        $this->assertSame('2.00', $usage['projected']);
        $this->assertSame('ok', $usage['level']);
    }

    public function test_converted_vl_does_not_count_as_taken(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'converted_to_sl');
        $this->seedLeave(801, $this->vlTypeId, '2026-08-03', '2026-08-04', 'converted_to_el');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'VL');

        $this->assertSame('0.00', $usage['taken']);
        $this->assertSame('1.00', $usage['projected']);
    }

    public function test_pending_conversion_still_counts_as_taken(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'pending_conversion');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'VL');

        $this->assertSame('8.00', $usage['taken']);
        $this->assertSame('9.00', $usage['projected']);
        $this->assertSame('at_cap', $usage['level']);
    }

    public function test_span_across_quarters_clips_workdays(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-30', '2026-10-01', DayType::Full, 'VL');

        $this->assertCount(2, $usage['quarters']);
        $this->assertSame('2026-Q3', $usage['quarters'][0]['quarter']);
        $this->assertSame('1.00', $usage['quarters'][0]['this_request']);
        $this->assertSame('9.00', $usage['quarters'][0]['projected']);
        $this->assertSame('at_cap', $usage['quarters'][0]['level']);
        $this->assertSame('2026-Q4', $usage['quarters'][1]['quarter']);
        $this->assertSame('1.00', $usage['quarters'][1]['this_request']);
        $this->assertSame('1.00', $usage['quarters'][1]['projected']);
        $this->assertSame('at_cap', $usage['level']);
        $this->assertSame('2026-Q3', $usage['quarter']);
    }

    public function test_after_confirmed_does_not_double_count_this_leave(): void
    {
        $this->seedLeave(801, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');
        $leave = $this->seedLeave(801, $this->vlTypeId, '2026-09-14', '2026-09-15', 'confirmed');

        $usage = $this->service->afterConfirmed($leave);

        $this->assertSame('10.00', $usage['taken']);
        $this->assertSame('2.00', $usage['this_request']);
        $this->assertSame('10.00', $usage['projected']);
        $this->assertSame('exceeded', $usage['level']);
    }

    public function test_other_employees_vl_is_ignored(): void
    {
        $this->seedLeave(802, $this->vlTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'VL');

        $this->assertSame('0.00', $usage['taken']);
        $this->assertSame('1.00', $usage['projected']);
        $this->assertSame('ok', $usage['level']);
    }

    public function test_sick_leave_in_the_quarter_is_ignored(): void
    {
        $this->seedLeave(801, $this->slTypeId, '2026-07-01', '2026-07-10', 'confirmed');

        $usage = $this->service->preview(801, '2026-09-14', '2026-09-14', DayType::Full, 'VL');

        $this->assertSame('0.00', $usage['taken']);
    }

    private function seedLeave(
        int $empId,
        int $leaveTypeId,
        string $startDate,
        string $endDate,
        string $status,
        string $dayType = 'full',
    ): LeaveInfo {
        $duration = $dayType === 'full' ? '8.00' : '0.50';

        return LeaveInfo::query()->create([
            'emp_id' => $empId,
            'filed_by_emp_id' => $empId,
            'leave_type_id' => $leaveTypeId,
            'duration' => $duration,
            'paid_days' => $duration,
            'lwop_days' => '0.00',
            'day_type' => $dayType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'leave_reason' => 'test',
            'status' => $status,
            'confirmed_at' => $status === 'pending_approval' ? null : '2026-07-01 08:00:00',
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('leave_info');
        Schema::dropIfExists('leave_type');
        Schema::dropIfExists('kdtholiday');

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
            $table->boolean('quarterly_vl_excess_notified')->default(false);
            $table->timestamps();
        });

        Schema::create('kdtholiday', function (Blueprint $table): void {
            $table->increments('fldID');
            $table->date('fldDate')->nullable();
            $table->string('fldHoliday')->nullable();
            $table->unsignedTinyInteger('fldHolidayType')->nullable();
            $table->unsignedInteger('fldLocID')->nullable();
            $table->string('fldLocation')->nullable();
        });
    }
}
