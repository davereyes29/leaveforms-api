<?php

namespace App\Enums\Leave;

enum DayType: string
{
    case Full = 'full';
    case HalfAm = 'half_am';
    case HalfPm = 'half_pm';

    /**
     * AM/PM suffix for half-day duration display in mail.
     */
    public function periodLabel(): ?string
    {
        return match ($this) {
            self::HalfAm => 'AM',
            self::HalfPm => 'PM',
            self::Full => null,
        };
    }
}
