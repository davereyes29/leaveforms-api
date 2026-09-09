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
    public function test_confirm_sends_exceeded_mail_when_over_cap(): void
    {
        $leave = $this->leave();
        $usage = $this->usage(exceeded: true);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('afterConfirmed')->once()->with($leave)->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->with($usage)->andReturn(true);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldReceive('notifyAdminOnQuarterlyVlExceeded')->once()->with($leave, $usage);

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onConfirmed($leave);
    }

    public function test_confirm_skips_when_not_over_cap(): void
    {
        $leave = $this->leave();
        $usage = $this->usage(exceeded: false);

        $usageService = Mockery::mock(VacationLeaveQuarterUsageService::class);
        $usageService->shouldReceive('afterConfirmed')->once()->with($leave)->andReturn($usage);
        $usageService->shouldReceive('exceedsCap')->with($usage)->andReturn(false);

        $notifications = Mockery::mock(LeaveNotificationService::class);
        $notifications->shouldNotReceive('notifyAdminOnQuarterlyVlExceeded');

        $service = new VacationLeaveQuarterNoticeService($usageService, $notifications);
        $service->onConfirmed($leave);
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
            'taken' => $exceeded ? '9.50' : '9.00',
            'this_request' => '0.50',
            'projected' => $exceeded ? '9.50' : '9.00',
            'cap' => '9.00',
            'level' => $exceeded ? 'exceeded' : 'at_cap',
            'quarters' => [],
        ];
    }

    private function leave(): LeaveInfo
    {
        $leave = new LeaveInfo([
            'emp_id' => 801,
            'day_type' => DayType::Full,
            'duration' => '0.50',
            'start_date' => '2026-09-29',
            'end_date' => '2026-09-29',
        ]);
        $leave->setRelation('leaveType', new LeaveType(['code' => 'VL', 'type' => 'Vacation Leave']));

        return $leave;
    }
}
