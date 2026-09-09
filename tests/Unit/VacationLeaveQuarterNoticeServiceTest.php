<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveNotificationService;
use App\Services\Leave\VacationLeaveQuarterNoticeService;
use App\Services\Leave\VacationLeaveQuarterUsageService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class VacationLeaveQuarterNoticeServiceTest extends TestCase
{
    public function test_submit_over_cap_flags_and_sends_impending_mail(): void
    {
        $leave = $this->leave();
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->with($usage)->andReturn(true);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlImpending')
            ->once()
            ->with(Mockery::on(fn (LeaveInfo $notified) => $notified->quarterly_vl_excess_notified === true), $usage);

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onSubmitted($leave);

        $this->assertTrue($leave->quarterly_vl_excess_notified);
    }

    public function test_submit_at_cap_does_not_notify_admin(): void
    {
        $leave = $this->leave();
        $usage = $this->usage(exceeded: false);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->with($usage)->andReturn(false);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlImpending');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onSubmitted($leave);

        $this->assertFalse($leave->quarterly_vl_excess_notified);
    }

    public function test_edit_that_newly_exceeds_sends_impending_mail(): void
    {
        $leave = $this->leave();
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->andReturn(true);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlImpending')->once();

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onModified($leave, wasNotified: false);

        $this->assertTrue($leave->quarterly_vl_excess_notified);
    }

    public function test_edit_that_stays_over_cap_does_not_mail_again(): void
    {
        $leave = $this->leave(notified: true);
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->andReturn(true);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlImpending');
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlWithdrawn');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onModified($leave, wasNotified: true);

        $this->assertTrue($leave->quarterly_vl_excess_notified);
    }

    public function test_edit_back_under_cap_sends_withdrawn_mail(): void
    {
        $leave = $this->leave(notified: true);
        $usage = $this->usage(exceeded: false);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->andReturn(false);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlWithdrawn')
            ->once()
            ->with(Mockery::type(LeaveInfo::class), $usage, 'edited');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onModified($leave, wasNotified: true);

        $this->assertFalse($leave->quarterly_vl_excess_notified);
    }

    public function test_confirm_sends_exceeded_mail_only_when_flagged(): void
    {
        $leave = $this->leave(notified: true);
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('afterConfirmed')->once()->with($leave)->andReturn($usage);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlExceeded')->once()->with($leave, $usage);

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onConfirmed($leave);
    }

    public function test_confirm_skips_unflagged_leave(): void
    {
        $leave = $this->leave(notified: false);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldNotReceive('afterConfirmed');

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlExceeded');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onConfirmed($leave);
    }

    public function test_cancel_sends_withdrawn_mail_when_flagged(): void
    {
        $leave = $this->leave(notified: true);
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('preview')->once()->andReturn($usage);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlWithdrawn')
            ->once()
            ->with($leave, $usage, 'cancelled');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onWithdrawn($leave, 'cancelled');
    }

    public function test_cancel_skips_unflagged_leave(): void
    {
        $leave = $this->leave(notified: false);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldNotReceive('preview');

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlWithdrawn');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onWithdrawn($leave, 'cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(bool $exceeded): array
    {
        return [
            'applicable' => true,
            'quarter' => '2026-Q3',
            'period' => ['start_date' => '2026-07-01', 'end_date' => '2026-09-30'],
            'taken' => '8.00',
            'this_request' => $exceeded ? '2.00' : '1.00',
            'projected' => $exceeded ? '10.00' : '9.00',
            'cap' => '9.00',
            'level' => $exceeded ? 'exceeded' : 'at_cap',
            'quarters' => [],
        ];
    }

    private function leave(bool $notified = false): LeaveInfo
    {
        $leave = Mockery::mock(LeaveInfo::class)->makePartial();
        $leave->emp_id = 801;
        $leave->day_type = DayType::Full;
        $leave->start_date = Carbon::parse('2026-09-14');
        $leave->end_date = Carbon::parse('2026-09-15');
        $leave->quarterly_vl_excess_notified = $notified;
        $leave->setRelation('leaveType', new LeaveType(['code' => 'VL', 'type' => 'Vacation Leave']));
        $leave->shouldReceive('loadMissing')->andReturnSelf();
        $leave->shouldReceive('update')->andReturnUsing(function (array $attributes) use ($leave): bool {
            foreach ($attributes as $key => $value) {
                $leave->{$key} = $value;
            }

            return true;
        });

        return $leave;
    }
}
