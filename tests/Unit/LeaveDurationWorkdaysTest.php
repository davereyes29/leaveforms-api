<?php

namespace Tests\Unit;

use App\Services\Leave\LeaveDurationService;
use PHPUnit\Framework\TestCase;

class LeaveDurationWorkdaysTest extends TestCase
{
    public function test_workdays_skip_weekends_and_holidays(): void
    {
        $service = new LeaveDurationService;

        $dates = $service->workdaysBetween(
            '2026-07-15',
            '2026-07-21',
            '2026-07-01',
            '2026-07-31',
            ['2026-07-16'],
        );

        $this->assertSame(
            ['2026-07-15', '2026-07-17', '2026-07-20', '2026-07-21'],
            $dates,
        );
    }

    public function test_workdays_are_clipped_to_the_visible_range(): void
    {
        $service = new LeaveDurationService;

        $dates = $service->workdaysBetween(
            '2026-06-29',
            '2026-07-03',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03'],
            $dates,
        );
    }

    public function test_workdays_outside_the_clip_range_are_empty(): void
    {
        $service = new LeaveDurationService;

        $dates = $service->workdaysBetween(
            '2026-06-01',
            '2026-06-05',
            '2026-07-01',
            '2026-07-31',
            [],
        );

        $this->assertSame([], $dates);
    }

    public function test_calendar_dates_include_weekends(): void
    {
        $service = new LeaveDurationService;

        $dates = $service->calendarDatesBetween(
            '2026-09-01',
            '2026-09-07',
            '2026-09-01',
            '2026-09-07',
        );

        $this->assertSame(
            [
                '2026-09-01',
                '2026-09-02',
                '2026-09-03',
                '2026-09-04',
                '2026-09-05',
                '2026-09-06',
                '2026-09-07',
            ],
            $dates,
        );
    }
}
