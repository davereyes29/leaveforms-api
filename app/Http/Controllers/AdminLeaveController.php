<?php

namespace App\Http\Controllers;

use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\IndexAdminLeavesRequest;
use App\Http\Resources\GroupRefResource;
use App\Http\Resources\PendingApprovalResource;
use Illuminate\Http\Request;
use App\Services\Leave\AdminLeaveListService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLeaveController extends Controller
{
    public function __construct(
        private readonly AdminLeaveListService $adminLeaveListService,
    ) {}

    /**
     * GET /api/admin/leaves — org-wide All Leave Requests (admin only).
     *
     * Optional query: ?status=confirmed&code=VL&group_id=16&sort=desc&page=1&per_page=15
     */
    public function index(IndexAdminLeavesRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');

        $leaves = $this->adminLeaveListService->paginateForAdmin(
            $request->employee(),
            $request->enum('status', LeaveStatus::class),
            $request->validated('code'),
            $request->integer('per_page', 15),
            $request->validated('sort') ?? 'desc',
            $groupId !== null ? (int) $groupId : null,
        );

        return PendingApprovalResource::collection($leaves);
    }

    /**
     * GET /api/admin/groups — org-wide group_list for admin All Leave Requests filter.
     */
    public function groups(Request $request): AnonymousResourceCollection
    {
        $groups = $this->adminLeaveListService->groupsForAdmin($request->employee());

        return GroupRefResource::collection($groups);
    }
}
