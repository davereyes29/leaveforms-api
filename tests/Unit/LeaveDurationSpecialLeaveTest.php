<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Services\Leave\LeaveDurationService;
use App\Services\Leave\SpecialLeaveAllotment;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class LeaveDurationSpecialLeaveTest extends TestCase
{
    public function test_ltd_death_allows_the_23_day_maximum(): void
    {
        $service = new LeaveDurationService;
        $allotment = new SpecialLeaveAllotment(23, true);

        $this->assertSame(
            '23.00',
            $service->calculate('2026-09-10', '2026-10-02', DayType::Full, 'SPL', $allotment),
        );
    }

    public function test_ltd_death_allows_a_shorter_span(): void
    {
        $service = new LeaveDurationService;
        $allotment = new SpecialLeaveAllotment(23, true);

        $this->assertSame(
            '22.00',
            $service->calculate('2026-09-10', '2026-10-01', DayType::Full, 'SPL', $allotment),
        );
        $this->assertSame(
            '1.00',
            $service->calculate('2026-09-10', '2026-09-10', DayType::Full, 'SPL', $allotment),
        );
    }

    public function test_ltd_death_rejects_a_longer_span(): void
    {
        $service = new LeaveDurationService;
        $allotment = new SpecialLeaveAllotment(23, true);

        try {
            $service->calculate('2026-09-10', '2026-10-03', DayType::Full, 'SPL', $allotment);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
            $this->assertStringContainsString('at most 23 calendar days', $e->errors()['end_date'][0]);
            $this->assertStringContainsString('2026-10-02', $e->errors()['end_date'][0]);
        }
    }

    public function test_workday_special_leave_allows_one_day_through_the_maximum(): void
    {
        $service = $this->workdayServiceIgnoringHolidays();
        $allotment = new SpecialLeaveAllotment(6, false);

        $this->assertSame(
            '1.00',
            $service->calculate('2026-09-10', '2026-09-10', DayType::Full, 'SPL', $allotment),
        );
        $this->assertSame(
            '6.00',
            $service->calculate('2026-09-10', '2026-09-17', DayType::Full, 'SPL', $allotment),
        );
    }

    public function test_workday_special_leave_rejects_more_than_the_maximum(): void
    {
        $service = $this->workdayServiceIgnoringHolidays();
        $allotment = new SpecialLeaveAllotment(6, false);

        try {
            $service->calculate('2026-09-10', '2026-09-18', DayType::Full, 'SPL', $allotment);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
            $this->assertStringContainsString('at most 6 workdays', $e->errors()['end_date'][0]);
            $this->assertStringContainsString('2026-09-17', $e->errors()['end_date'][0]);
        }
    }

    public function test_workday_special_leave_allows_a_weekend_end_that_does_not_add_workdays(): void
    {
        $service = $this->workdayServiceIgnoringHolidays();
        $allotment = new SpecialLeaveAllotment(5, false);

        $this->assertSame(
            '5.00',
            $service->calculate('2026-09-07', '2026-09-13', DayType::Full, 'SPL', $allotment),
        );
    }

    public function test_workday_special_leave_rejects_a_weekend_start(): void
    {
        $this->expectException(ValidationException::class);

        (new LeaveDurationService)->calculate(
            '2026-09-12',
            '2026-09-14',
            DayType::Full,
            'SPL',
            new SpecialLeaveAllotment(6, false),
        );
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

    private function workdayServiceIgnoringHolidays(): LeaveDurationService
    {
        $service = Mockery::mock(LeaveDurationService::class)->makePartial();
        $service->shouldReceive('isWorkday')->andReturnUsing(
            fn (Carbon $date): bool => ! $date->isWeekend(),
        );

        return $service;
    }
}
