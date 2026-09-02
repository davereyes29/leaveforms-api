<?php

namespace Tests\Unit;

use App\Services\Leave\VacationLeaveNoticePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VacationLeaveNoticePolicyTest extends TestCase
{
    private VacationLeaveNoticePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new VacationLeaveNoticePolicy;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 08:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_self_filed_vl_of_three_workdays_is_blocked_inside_one_calendar_month(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->policy->assertAdvanceNotice('VL', '3.00', '2026-09-07', false);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
            $this->assertStringContainsString('3 or more consecutive days', $e->errors()['start_date'][0]);
            $this->assertStringContainsString('Ask your approver to file this request', $e->errors()['start_date'][0]);

            throw $e;
        }
    }

    public function test_self_filed_vl_may_start_on_the_one_calendar_month_boundary(): void
    {
        $this->policy->assertAdvanceNotice('VL', '3.00', '2026-10-01', false);

        $this->addToAssertionCount(1);
    }

    public function test_self_filed_vl_under_three_workdays_is_not_gated(): void
    {
        $this->policy->assertAdvanceNotice('VL', '2.00', '2026-09-07', false);

        $this->addToAssertionCount(1);
    }

    public function test_on_behalf_vl_skips_the_notice_gate(): void
    {
        $this->policy->assertAdvanceNotice('VL', '3.00', '2026-09-07', true);

        $this->addToAssertionCount(1);
    }

    public function test_other_leave_types_are_not_gated(): void
    {
        $this->policy->assertAdvanceNotice('SL', '3.00', '2026-09-07', false);
        $this->policy->assertAdvanceNotice('EL', '5.00', '2026-09-07', false);

        $this->addToAssertionCount(1);
    }

    public function test_january_31_uses_calendar_month_no_overflow(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-31 08:00:00', 'Asia/Manila'));

        $this->expectException(ValidationException::class);

        $this->policy->assertAdvanceNotice('VL', '3.00', '2026-02-27', false);
    }

    public function test_january_31_allows_february_28(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-31 08:00:00', 'Asia/Manila'));

        $this->policy->assertAdvanceNotice('VL', '3.00', '2026-02-28', false);

        $this->addToAssertionCount(1);
    }
}
