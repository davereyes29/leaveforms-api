<?php

namespace App\Services\Leave;

use App\Enums\Leave\ApproverType;
use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Formspic;
use App\Models\Leave\LeaveApprovalRequirement;

/**
 * Value object describing an approver's PIC context at action time.
 */
readonly class ApproverContext
{
    /**
     * @param  list<string>  $groupCodes
     */
    public function __construct(
        public int $empId,
        public ?int $approvalLevel,
        public array $groupCodes,
        public bool $isPresident,
        public bool $isAdmin = false,
    ) {}

    public function approverType(): ?ApproverType
    {
        if ($this->isPresident) {
            return ApproverType::President;
        }

        if ($this->isAdmin) {
            return ApproverType::Admin;
        }

        return match ($this->approvalLevel) {
            1 => ApproverType::Leader,
            2 => ApproverType::Am,
            3 => ApproverType::Dm,
            4 => ApproverType::Sm,
            default => null,
        };
    }
}
