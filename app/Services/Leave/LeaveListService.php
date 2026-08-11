<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries for leave request lists and detail.
 */
class LeaveListService
{
    /**
     * Relations needed for LeaveResource (detail / mutation responses).
     *
     * @var list<string>
     */
    public const DETAIL_RELATIONS = [
        'leaveType',
        'approvalRequirements.history',
        'conversion.fromLeaveType',
        'conversion.toLeaveType',
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    public function paginateForEmployee(
        int $empId,
        ?LeaveStatus $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return LeaveInfo::query()
            ->with('leaveType')
            ->where('emp_id', $empId)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Load a leave for GET /api/leaves/{leave} after access check.
     *
     * @throws AuthorizationException
     */
    public function detailForViewer(LeaveInfo $leave, Employee $viewer): LeaveInfo
    {
        if (! $this->canView($leave, $viewer)) {
            throw new AuthorizationException('You are not allowed to view this leave request.');
        }

        $leave->load(self::DETAIL_RELATIONS);

        return $leave;
    }

    public function canView(LeaveInfo $leave, Employee $viewer): bool
    {
        $viewerId = (int) $viewer->id;

        if ($viewerId === (int) $leave->emp_id || $viewerId === (int) $leave->filed_by_emp_id) {
            return true;
        }

        $context = $this->approverResolver->contextFor($viewerId);

        if ($context->isAdmin) {
            return true;
        }

        $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

        // Same PIC coverage as GET /approvals (any status).
        if ($filerGroup !== null && in_array($filerGroup, $context->groupCodes, true)) {
            return true;
        }

        $leave->loadMissing('approvalRequirements.history');

        foreach ($leave->approvalRequirements as $requirement) {
            if ($requirement->history !== null
                && (int) $requirement->history->approver_emp_id === $viewerId) {
                return true;
            }
        }

        if ($leave->status !== LeaveStatus::PendingApproval) {
            return false;
        }

        foreach ($leave->approvalRequirements as $requirement) {
            if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                return true;
            }
        }

        return false;
    }
}
