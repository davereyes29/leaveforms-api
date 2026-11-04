<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveTypeRules;

/**
 * Builds the frontend URL for a leave email button.
 */
final class LeaveMailLink
{
    public static function approverReview(int $leaveId): string
    {
        return self::to('/team/leave-approvals/'.$leaveId);
    }

    public static function approverQueue(): string
    {
        return self::to('/team/leave-approvals');
    }

    public static function adminValidate(int $leaveId): string
    {
        return self::to('/admin/leave-requests/'.$leaveId.'/validate');
    }

    public static function adminView(int $leaveId): string
    {
        return self::to('/admin/leave-requests/'.$leaveId);
    }

    public static function employeeView(int $leaveId): string
    {
        return self::to('/my-leave/requests/'.$leaveId);
    }

    /**
     * Page where the recipient can act on a pending leave.
     * Admin-approved types open the admin validate page; other types open the approver review page.
     */
    public static function pendingReview(LeaveInfo $leave): string
    {
        if (self::isAdminApproved($leave)) {
            return self::adminValidate((int) $leave->id);
        }

        return self::approverReview((int) $leave->id);
    }

    /**
     * Page where the recipient can read a cancelled leave.
     * Admin-approved types open the admin record; other types open the approver review page.
     */
    public static function cancellationNotice(LeaveInfo $leave): string
    {
        if (self::isAdminApproved($leave)) {
            return self::adminView((int) $leave->id);
        }

        return self::approverReview((int) $leave->id);
    }

    public static function to(string $path): string
    {
        $base = rtrim((string) config('leave.frontend_url'), '/');

        if ($base === '') {
            return '';
        }

        return $base.'/'.ltrim($path, '/');
    }

    private static function isAdminApproved(LeaveInfo $leave): bool
    {
        $leave->loadMissing('leaveType');

        return LeaveTypeRules::isAdminApproved((string) $leave->leaveType?->code);
    }
}
