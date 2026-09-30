<?php

namespace App\Http\Controllers;

use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\IndexApprovalsRequest;
use App\Http\Requests\Leave\IndexPendingApprovalsRequest;
use App\Http\Requests\Leave\RejectLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Http\Resources\PendingApprovalResource;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\ApproverLeaveListService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\PendingApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveApprovalController extends Controller
{
    public function __construct(
        private readonly LeaveApprovalService $approvalService,
        private readonly PendingApprovalService $pendingApprovalService,
        private readonly ApproverLeaveListService $approverLeaveListService,
    ) {}

    /**
     * GET /api/approvals — All Leave Requests for employees in the approver's PIC groups
     * (president: all P4 employees).
     *
     * Optional query: ?q=alex&group_id=16&status=confirmed&code=VL&sort=desc&page=1&per_page=15
     */
    public function index(IndexApprovalsRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');

        $leaves = $this->approverLeaveListService->paginateForApprover(
            $request->employee(),
            $request->enum('status', LeaveStatus::class),
            $request->validated('code'),
            $request->integer('per_page', 15),
            $request->validated('sort') ?? 'desc',
            $groupId !== null ? (int) $groupId : null,
            is_string($q) && $q !== '' ? $q : null,
        );

        return PendingApprovalResource::collection($leaves);
    }

    /**
     * GET /api/approvals/pending — leaves this approver can act on (slim inbox rows).
     *
     * Optional query: ?q=alex
     */
    public function pending(IndexPendingApprovalsRequest $request): AnonymousResourceCollection
    {
        $q = $request->validated('q');

        $leaves = $this->pendingApprovalService->forApprover(
            $request->employee(),
            is_string($q) && $q !== '' ? $q : null,
        );

        return PendingApprovalResource::collection($leaves);
    }

    /**
     * POST /api/leaves/{leave}/approve
     */
    public function approve(Request $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->approvalService->approve($leave, $request->employee());

        return LeaveResource::make($leave);
    }

    /**
     * POST /api/leaves/{leave}/reject
     */
    public function reject(RejectLeaveRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->approvalService->reject(
            $leave,
            $request->employee(),
            $request->validated('remarks'),
        );

        return LeaveResource::make($leave);
    }
}
