<?php

namespace App\Http\Controllers;

use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\IndexAdminEmployeesRequest;
use App\Http\Requests\Leave\IndexAdminLeavesRequest;
use App\Http\Resources\AdminEmployeeRefResource;
use App\Http\Resources\DepartmentRefResource;
use App\Http\Resources\GroupRefResource;
use App\Http\Resources\PendingApprovalResource;
use App\Services\Leave\AdminLeaveListService;
use Illuminate\Http\Request;
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
     * GET /api/admin/groups — org-wide group_list for admin filters.
     */
    public function groups(Request $request): AnonymousResourceCollection
    {
        $groups = $this->adminLeaveListService->groupsForAdmin($request->employee());

        return GroupRefResource::collection($groups);
    }

    /**
     * GET /api/admin/departments — org-wide department_list for admin filters.
     */
    public function departments(Request $request): AnonymousResourceCollection
    {
        $departments = $this->adminLeaveListService->departmentsForAdmin($request->employee());

        return DepartmentRefResource::collection($departments);
    }

    /**
     * GET /api/admin/employees — slim org-wide employee list for admin pickers.
     *
     * Optional query: ?group_id=16
     */
    public function employees(IndexAdminEmployeesRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');

        $employees = $this->adminLeaveListService->employeesForAdmin(
            $request->employee(),
            $groupId !== null ? (int) $groupId : null,
        );

        return AdminEmployeeRefResource::collection($employees);
    }
}
