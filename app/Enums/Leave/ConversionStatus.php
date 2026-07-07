<?php

namespace App\Enums\Leave;

enum ConversionStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
