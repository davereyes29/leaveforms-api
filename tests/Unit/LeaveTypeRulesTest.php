<?php

namespace Tests\Unit;

use App\Services\Leave\LeaveTypeRules;
use Tests\TestCase;

class LeaveTypeRulesTest extends TestCase
{
    public function test_maternity_and_paternity_are_filable(): void
    {
        $this->assertSame(
            ['VL', 'SL', 'PTB', 'EL', 'LL', 'ML', 'PL', 'SPL'],
            LeaveTypeRules::filableCodes(),
        );
    }

    public function test_parental_leave_grants_credits_on_submit(): void
    {
        $this->assertTrue(LeaveTypeRules::grantsCreditsOnSubmit('ML'));
        $this->assertTrue(LeaveTypeRules::grantsCreditsOnSubmit('PL'));
        $this->assertFalse(LeaveTypeRules::grantsCreditsOnSubmit('VL'));
        $this->assertFalse(LeaveTypeRules::grantsCreditsOnSubmit('LL'));
        $this->assertFalse(LeaveTypeRules::grantsCreditsOnSubmit('SPL'));
        $this->assertFalse(LeaveTypeRules::usesCredits('SPL'));
        $this->assertTrue(LeaveTypeRules::usesCredits('EL'));
    }

    public function test_special_leave_is_admin_approved_full_day_with_supporting_documents(): void
    {
        $this->assertTrue(LeaveTypeRules::isAdminApproved('SPL'));
        $this->assertTrue(LeaveTypeRules::requiresFullDay('SPL'));
        $this->assertTrue(LeaveTypeRules::requiresAttachment('SPL'));
        $this->assertFalse(LeaveTypeRules::allowsLwop('SPL'));
        $this->assertFalse(LeaveTypeRules::isUnpaid('SPL'));
        $this->assertFalse(LeaveTypeRules::usesCalendarDays('SPL'));
        $this->assertSame(
            [
                'singular' => 'supporting document',
                'plural' => 'supporting documents',
            ],
            LeaveTypeRules::attachmentLabel('SPL'),
        );
    }

    public function test_maternity_uses_exact_calendar_days_and_paternity_uses_exact_workdays(): void
    {
        $this->assertTrue(LeaveTypeRules::usesCalendarDays('ML'));
        $this->assertFalse(LeaveTypeRules::usesCalendarDays('PL'));
        $this->assertSame(105, LeaveTypeRules::exactCalendarDays('ML'));
        $this->assertNull(LeaveTypeRules::exactCalendarDays('PL'));
        $this->assertSame(7, LeaveTypeRules::exactWorkdays('PL'));
        $this->assertNull(LeaveTypeRules::exactWorkdays('ML'));
    }

    public function test_parental_leave_is_admin_approved_full_day(): void
    {
        foreach (['ML', 'PL'] as $code) {
            $this->assertTrue(LeaveTypeRules::isAdminApproved($code));
            $this->assertTrue(LeaveTypeRules::requiresFullDay($code));
            $this->assertFalse(LeaveTypeRules::allowsLwop($code));
            $this->assertFalse(LeaveTypeRules::isUnpaid($code));
        }

        $this->assertFalse(LeaveTypeRules::requiresAttachment('ML'));
        $this->assertTrue(LeaveTypeRules::requiresAttachment('PL'));
        $this->assertTrue(LeaveTypeRules::requiresAttachment('LL'));
        $this->assertSame(
            [
                'singular' => 'supporting document',
                'plural' => 'supporting documents',
            ],
            LeaveTypeRules::attachmentLabel('LL'),
        );
        $this->assertSame(
            [
                'singular' => 'Paternity Notification Form',
                'plural' => 'Paternity Notification Forms',
            ],
            LeaveTypeRules::attachmentLabel('PL'),
        );
        $this->assertNull(LeaveTypeRules::attachmentLabel('ML'));
        $this->assertNull(LeaveTypeRules::attachmentLabel('VL'));
    }
}
