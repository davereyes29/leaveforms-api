<?php

namespace App\Services\Leave;

use App\Models\Leave\LeaveInfo;

/**
 * Emails admin when confirmed VL exceeds 9 days in a calendar quarter.
 */
class VacationLeaveQuarterNoticeService
{
    public function __construct(
        private readonly VacationLeaveQuarterUsageService $usageService,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    public function onConfirmed(LeaveInfo $leave): void
    {
        $usage = $this->usageService->afterConfirmed($leave);

        if (! $this->usageService->exceedsCap($usage)) {
            return;
        }

        $this->notificationService->notifyAdminOnQuarterlyVlExceeded($leave, $usage);
    }
}
