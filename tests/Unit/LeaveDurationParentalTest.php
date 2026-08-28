<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Services\Leave\LeaveDurationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveDurationParentalTest extends TestCase
{
    public function test_maternity_leave_is_exactly_105_inclusive_calendar_days(): void
    {
        $service = new LeaveDurationService;

        $this->assertSame(
            '105.00',
            $service->calculate('2026-09-01', '2026-12-14', DayType::Full, 'ML'),
        );
    }

    public function test_maternity_leave_rejects_a_shorter_span(): void
    {
        $service = new LeaveDurationService;

        try {
            $service->calculate('2026-09-01', '2026-12-13', DayType::Full, 'ML');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
            $this->assertStringContainsString('2026-12-14', $e->errors()['end_date'][0]);
        }
    }

    public function test_maternity_leave_rejects_half_day(): void
    {
        $this->expectException(ValidationException::class);

        (new LeaveDurationService)->calculate('2026-09-01', '2026-12-14', DayType::HalfAm, 'ML');
    }

    public function test_paternity_leave_rejects_a_weekend_start(): void
    {
        $service = new LeaveDurationService;

        try {
            $service->calculate('2026-08-16', '2026-08-24', DayType::Full, 'PL');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
            $this->assertStringContainsString('workday', $e->errors()['start_date'][0]);
        }
    }
}
