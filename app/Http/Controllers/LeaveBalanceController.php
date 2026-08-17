<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveOnBehalfAuthorizationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
    ) {}

    /**
     * GET /api/balances — leave credits for self or an on-behalf target.
     *
     * Optional query: ?emp_id=212&code=VL (code is case-insensitive)
     */
    public function index(IndexLeaveBalanceRequest $request): AnonymousResourceCollection
    {
        $empId = $request->validated('emp_id');

        $target = $this->onBehalfAuthorization->resolveEmployeeOnLeave(
            $request->employee(),
            $empId !== null ? (int) $empId : null,
        );

        $balances = $this->balanceService->getForEmployee(
            $target->id,
            $request->validated('code'),
        );

        return LeaveBalanceResource::collection($balances);
    }
}
