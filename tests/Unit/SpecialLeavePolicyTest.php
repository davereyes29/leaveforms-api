<?php

namespace Tests\Unit;

use App\Enums\Leave\SpecialLeaveAssignmentType;
use App\Enums\Leave\SpecialLeaveReason;
use App\Services\Leave\SpecialLeavePolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SpecialLeavePolicyTest extends TestCase
{
    private SpecialLeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SpecialLeavePolicy;
    }

    public function test_philippines_based_death_of_spouse_is_seven_workdays(): void
    {
        $allotment = $this->policy->allotment(
            SpecialLeaveAssignmentType::PhilippinesBased,
            SpecialLeaveReason::DeathSpouse,
        );

        $this->assertSame(7, $allotment->days);
        $this->assertFalse($allotment->usesCalendarDays);
    }

    public function test_philippines_based_employee_marriage_is_six_workdays(): void
    {
        $allotment = $this->policy->allotment(
            SpecialLeaveAssignmentType::PhilippinesBased,
            SpecialLeaveReason::EmployeeMarriage,
        );

        $this->assertSame(6, $allotment->days);
        $this->assertFalse($allotment->usesCalendarDays);
    }

    public function test_long_term_overseas_qualifying_death_is_23_calendar_days(): void
    {
        $allotment = $this->policy->allotment(
            SpecialLeaveAssignmentType::LongTermOverseas,
            SpecialLeaveReason::DeathParent,
        );

        $this->assertSame(23, $allotment->days);
        $this->assertTrue($allotment->usesCalendarDays);
    }

    public function test_long_term_overseas_marriage_stays_workdays(): void
    {
        $allotment = $this->policy->allotment(
            SpecialLeaveAssignmentType::LongTermOverseas,
            SpecialLeaveReason::ChildMarriage,
        );

        $this->assertSame(2, $allotment->days);
        $this->assertFalse($allotment->usesCalendarDays);
    }

    public function test_long_term_overseas_rejects_child_spouse_death(): void
    {
        try {
            $this->policy->allotment(
                SpecialLeaveAssignmentType::LongTermOverseas,
                SpecialLeaveReason::DeathChildSpouse,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('special_leave_reason', $e->errors());
        }
    }

    public function test_long_term_overseas_rejects_sibling_spouse_death(): void
    {
        $this->expectException(ValidationException::class);

        $this->policy->allotment(
            SpecialLeaveAssignmentType::LongTermOverseas,
            SpecialLeaveReason::DeathSiblingSpouse,
        );
    }

    public function test_philippines_based_allows_child_spouse_death(): void
    {
        $allotment = $this->policy->allotment(
            SpecialLeaveAssignmentType::PhilippinesBased,
            SpecialLeaveReason::DeathChildSpouse,
        );

        $this->assertSame(3, $allotment->days);
        $this->assertFalse($allotment->usesCalendarDays);
    }
}
