<?php

namespace App\Enums\Leave;

enum SpecialLeaveAssignmentType: string
{
    case PhilippinesBased = 'philippines_based';

    case LongTermOverseas = 'long_term_overseas';

    public function label(): string
    {
        return match ($this) {
            self::PhilippinesBased => 'Philippines-Based / Short-Term Overseas',
            self::LongTermOverseas => 'Long-Term Overseas',
        };
    }

    public function isLongTermOverseas(): bool
    {
        return $this === self::LongTermOverseas;
    }
}
