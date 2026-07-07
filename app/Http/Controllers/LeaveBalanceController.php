<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * GET /api/balances — current employee's leave credits.
     *
     * Optional query: ?code=VL (case-insensitive)
     */
    public function index(IndexLeaveBalanceRequest $request): AnonymousResourceCollection
    {
        $employee = $request->employee();

        $balances = $this->balanceService->getForEmployee(
            $employee->id,
            $request->validated('code'),
        );

        return LeaveBalanceResource::collection($balances);
    }
}
