<?php

namespace App\Services\Leave;

use App\Enums\Leave\SpecialLeaveAssignmentType;
use App\Enums\Leave\SpecialLeaveReason;
use App\Models\Leave\LeaveInfo;
use Illuminate\Validation\ValidationException;

/**
 * Event matrix for Special Leave (working days vs LTD death calendar days).
 */
class SpecialLeavePolicy
{
    public function allotment(
        SpecialLeaveAssignmentType $assignment,
        SpecialLeaveReason $reason,
    ): SpecialLeaveAllotment {
        if ($assignment === SpecialLeaveAssignmentType::PhilippinesBased) {
            return new SpecialLeaveAllotment($this->generalWorkdays($reason), false);
        }

        if ($reason === SpecialLeaveReason::EmployeeMarriage) {
            return new SpecialLeaveAllotment(6, false);
        }

        if ($reason === SpecialLeaveReason::ChildMarriage) {
            return new SpecialLeaveAllotment(2, false);
        }

        if ($this->isUnavailableForLongTermOverseas($reason)) {
            throw ValidationException::withMessages([
                'special_leave_reason' => [
                    'This qualifying event is not available for Long-Term Overseas assignment.',
                ],
            ]);
        }

        return new SpecialLeaveAllotment($this->ltdDeathCalendarDays(), true);
    }

    public function usesCalendarDays(
        ?SpecialLeaveAssignmentType $assignment,
        ?SpecialLeaveReason $reason,
    ): bool {
        if ($assignment === null || $reason === null) {
            return false;
        }

        if ($this->isUnavailableForLongTermOverseas($reason)
            && $assignment === SpecialLeaveAssignmentType::LongTermOverseas) {
            return false;
        }

        return $this->allotment($assignment, $reason)->usesCalendarDays;
    }

    public function usesCalendarDaysForLeave(LeaveInfo $leave): bool
    {
        return $this->usesCalendarDays(
            $leave->assignment_type,
            $leave->special_leave_reason,
        );
    }

    public function isUnavailableForLongTermOverseas(SpecialLeaveReason $reason): bool
    {
        return in_array($reason, [
            SpecialLeaveReason::DeathChildSpouse,
            SpecialLeaveReason::DeathSiblingSpouse,
        ], true);
    }

    private function generalWorkdays(SpecialLeaveReason $reason): int
    {
        return match ($reason) {
            SpecialLeaveReason::EmployeeMarriage => 6,
            SpecialLeaveReason::ChildMarriage => 2,
            SpecialLeaveReason::DeathSpouse,
            SpecialLeaveReason::DeathParent,
            SpecialLeaveReason::DeathChild => 7,
            SpecialLeaveReason::DeathSibling,
            SpecialLeaveReason::DeathGrandparent,
            SpecialLeaveReason::DeathGrandchild => 5,
            SpecialLeaveReason::DeathSpouseParent => 4,
            SpecialLeaveReason::DeathChildSpouse => 3,
            SpecialLeaveReason::DeathSiblingSpouse,
            SpecialLeaveReason::DeathSpouseSibling,
            SpecialLeaveReason::DeathSpouseGrandparent => 2,
        };
    }

    private function ltdDeathCalendarDays(): int
    {
        return (int) config('leave.special_leave.ltd_death_calendar_days', 23);
    }
}
