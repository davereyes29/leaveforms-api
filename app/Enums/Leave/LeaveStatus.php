<?php

namespace App\Enums\Leave;

enum LeaveStatus: string
{
    case PendingApproval = 'pending_approval';
    case Confirmed = 'confirmed';
    case PendingConversion = 'pending_conversion';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case ConvertedToSl = 'converted_to_sl';
    case ConvertedToEl = 'converted_to_el';

    public function isConverted(): bool
    {
        return $this === self::ConvertedToSl || $this === self::ConvertedToEl;
    }
}
