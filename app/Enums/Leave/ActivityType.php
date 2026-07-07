<?php

namespace App\Enums\Leave;

enum ActivityType: string
{
    case Submitted = 'submitted';
    case Modified = 'modified';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case AttachmentAdded = 'attachment_added';
    case LeaveConfirmed = 'leave_confirmed';
    case ConversionRequested = 'conversion_requested';
    case ConversionApproved = 'conversion_approved';
    case ConversionRejected = 'conversion_rejected';
}
