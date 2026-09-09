<?php

namespace App\Services\Leave;

use App\Enums\Leave\DayType;
use App\Models\Leave\LeaveInfo;

/**
 * Flags VL that would exceed 9 confirmed days in a quarter and emails admin.
 *
 * Mail 1: impending (submit, or edit that newly exceeds).
 * Mail 2: exceeded (that leave is approved).
 * Mail 3: withdrawn (cancel, reject, or edit back to ≤ cap).
 */
class VacationLeaveQuarterNoticeService
{
    public function __construct(
        private readonly VacationLeaveQuarterUsageService $usageService,
        private readonly LeaveNotificationService $notificationService,
    ) {}

    public function onSubmitted(LeaveInfo $leave): void
    {
        $usage = $this->previewFor($leave);

        if (! $this->usageService->exceedsCap($usage)) {
            return;
        }

        $leave->update(['quarterly_vl_excess_notified' => true]);
        $this->notificationService->notifyAdminOnQuarterlyVlImpending($leave, $usage);
    }

    public function onModified(LeaveInfo $leave, bool $wasNotified): void
    {
        $usage = $this->previewFor($leave);
        $exceeds = $this->usageService->exceedsCap($usage);

        if ($exceeds && ! $wasNotified) {
            $leave->update(['quarterly_vl_excess_notified' => true]);
            $this->notificationService->notifyAdminOnQuarterlyVlImpending($leave, $usage);

            return;
        }

        if (! $exceeds && $wasNotified) {
            $leave->update(['quarterly_vl_excess_notified' => false]);
            $this->notificationService->notifyAdminOnQuarterlyVlWithdrawn($leave, $usage, 'edited');
        }
    }

    public function onConfirmed(LeaveInfo $leave): void
    {
        if (! $leave->quarterly_vl_excess_notified) {
            return;
        }

        $usage = $this->usageService->afterConfirmed($leave);
        $this->notificationService->notifyAdminOnQuarterlyVlExceeded($leave, $usage);
    }

    public function onWithdrawn(LeaveInfo $leave, string $reason): void
    {
        if (! $leave->quarterly_vl_excess_notified) {
            return;
        }

        $usage = $this->previewFor($leave);
        $this->notificationService->notifyAdminOnQuarterlyVlWithdrawn($leave, $usage, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFor(LeaveInfo $leave): array
    {
        $leave->loadMissing('leaveType');

        $dayType = $leave->day_type instanceof DayType
            ? $leave->day_type
            : DayType::from((string) $leave->day_type);

        return $this->usageService->preview(
            (int) $leave->emp_id,
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString(),
            $dayType,
            (string) ($leave->leaveType?->code ?? 'VL'),
        );
    }
}
