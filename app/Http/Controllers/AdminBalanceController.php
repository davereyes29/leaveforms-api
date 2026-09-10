<?php

namespace App\Http\Controllers;

use App\Enums\Leave\EmploymentStatus;
use App\Http\Requests\Leave\IndexAdminBalancesRequest;
use App\Http\Requests\Leave\StoreAdminBalanceAdjustmentRequest;
use App\Http\Resources\AdminEmployeeBalanceResource;
use App\Services\Leave\AdminBalanceListService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminBalanceController extends Controller
{
    public function __construct(
        private readonly AdminBalanceListService $adminBalanceListService,
    ) {}

    /**
     * GET /api/admin/balances — org-wide employee balances (admin only).
     *
     * Optional query: ?q=alex&department_id=1&group_id=16&employment_status=regular&sort=asc&page=1&per_page=15
     */
    public function index(IndexAdminBalancesRequest $request): AnonymousResourceCollection
    {
        $departmentId = $request->validated('department_id');
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');

        $employees = $this->adminBalanceListService->paginateForAdmin(
            $request->employee(),
            $request->integer('per_page', 15),
            $departmentId !== null ? (int) $departmentId : null,
            $groupId !== null ? (int) $groupId : null,
            $request->enum('employment_status', EmploymentStatus::class),
            is_string($q) && $q !== '' ? $q : null,
            $request->validated('sort') ?? 'asc',
        );

        return AdminEmployeeBalanceResource::collection($employees);
    }

    /**
     * POST /api/admin/balances — manual available-credit adjustment (admin only).
     *
     * Body: { emp_id, code, available_delta, remarks }. code: VL, SL, EL, PTB only.
     */
    public function store(StoreAdminBalanceAdjustmentRequest $request): AdminEmployeeBalanceResource
    {
        $employee = $this->adminBalanceListService->adjustForAdmin(
            $request->employee(),
            (int) $request->validated('emp_id'),
            $request->validated('code'),
            (string) $request->validated('available_delta'),
            $request->validated('remarks'),
        );

        return AdminEmployeeBalanceResource::make($employee);
    }
}
