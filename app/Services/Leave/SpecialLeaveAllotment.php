<?php

namespace App\Services\Leave;

/**
 * Maximum Special Leave span for one assignment type + qualifying event.
 */
final readonly class SpecialLeaveAllotment
{
    public function __construct(
        public int $days,
        public bool $usesCalendarDays,
    ) {}
}
