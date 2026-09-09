<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\SpecialLeaveAllotment;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveDurationSpecialLeaveTest extends TestCase
{
    public function test_ltd_death_is_exactly_23_inclusive_calendar_days(): void
    {
        $service = new LeaveDurationService;
        $allotment = new SpecialLeaveAllotment(23, true);

        $this->assertSame(
            '23.00',
            $service->calculate('2026-09-10', '2026-10-02', DayType::Full, 'SPL', $allotment),
        );
    }

    public function test_ltd_death_rejects_a_shorter_span(): void
    {
        $service = new LeaveDurationService;
        $allotment = new SpecialLeaveAllotment(23, true);

        try {
            $service->calculate('2026-09-10', '2026-10-01', DayType::Full, 'SPL', $allotment);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
            $this->assertStringContainsString('2026-10-02', $e->errors()['end_date'][0]);
        }
    }

    public function test_special_leave_rejects_half_day(): void
    {
        $this->expectException(ValidationException::class);

        (new LeaveDurationService)->calculate(
            '2026-09-10',
            '2026-10-02',
            DayType::HalfAm,
            'SPL',
            new SpecialLeaveAllotment(23, true),
        );
    }

    public function test_special_leave_requires_an_allotment(): void
    {
        try {
            (new LeaveDurationService)->calculate(
                '2026-09-10',
                '2026-10-02',
                DayType::Full,
                'SPL',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('special_leave_reason', $e->errors());
        }
    }
}
