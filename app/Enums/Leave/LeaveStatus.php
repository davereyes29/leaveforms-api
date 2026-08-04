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
}
