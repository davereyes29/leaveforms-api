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
    case AnnualCreditsDistributed = 'annual_credits_distributed';
    case RegularizationCreditsDistributed = 'regularization_credits_distributed';
    case ContractualCreditsDistributed = 'contractual_credits_distributed';
    case CreditsAdjusted = 'credits_adjusted';

    /**
     * Human-readable label for Activity Log UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Leave request submitted',
            self::Modified => 'Leave request modified',
            self::Cancelled => 'Leave request cancelled',
            self::Rejected => 'Leave request rejected',
            self::AttachmentAdded => 'Attachment added',
            self::LeaveConfirmed => 'Leave request approved',
            self::ConversionRequested => 'VL to SL conversion requested',
            self::ConversionApproved => 'VL to SL conversion approved',
            self::ConversionRejected => 'VL to SL conversion rejected',
            self::AnnualCreditsDistributed => 'Annual leave credits distributed',
            self::RegularizationCreditsDistributed => 'Newly regularized credits distributed',
            self::ContractualCreditsDistributed => 'Contractual leave credits distributed',
            self::CreditsAdjusted => 'Leave credits adjusted',
        };
    }

    public function isConversion(): bool
    {
        return match ($this) {
            self::ConversionRequested,
            self::ConversionApproved,
            self::ConversionRejected => true,
            default => false,
        };
    }
}
