<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\RejectLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\PendingApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveApprovalController extends Controller
{
    public function __construct(
        private readonly LeaveApprovalService $approvalService,
        private readonly PendingApprovalService $pendingApprovalService,
    ) {}

    /**
     * GET /api/approvals/pending — leaves this approver can act on.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $leaves = $this->pendingApprovalService->forApprover($request->employee());

        return LeaveResource::collection($leaves);
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
