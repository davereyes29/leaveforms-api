<?php

namespace App\Enums\Leave;

enum MonthlyAttendanceCutOff: string
{
    case Monthly = 'monthly';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::FirstHalf => 'First half',
            self::SecondHalf => 'Second half',
        };
    }
}
