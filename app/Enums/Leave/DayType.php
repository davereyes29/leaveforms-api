<?php

namespace App\Enums\Leave;

enum DayType: string
{
    case Full = 'full';
    case HalfAm = 'half_am';
    case HalfPm = 'half_pm';
}
