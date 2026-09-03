<?php

namespace App\Services\Leave;

use App\Enums\Leave\ConversionStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveConversion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin inbox for pending VL → SL and VL → EL conversion reviews.
 */
class PendingConversionService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return Collection<int, LeaveConversion>
     */
    public function forAdmin(Employee $admin): Collection
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view pending conversions.');
        }

        return LeaveConversion::query()
            ->with(['leaveInfo.leaveType', 'fromLeaveType', 'toLeaveType', 'attachments'])
            ->where('status', ConversionStatus::PendingReview)
            ->orderBy('created_at')
            ->get();
    }
}
