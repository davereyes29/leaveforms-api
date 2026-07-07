<?php

namespace App\Enums\Leave;

enum LeaveStatus: string
{
    case PendingApproval = 'pending_approval';
    case PartiallyApproved = 'partially_approved';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case ConvertedToSl = 'converted_to_sl';
}
