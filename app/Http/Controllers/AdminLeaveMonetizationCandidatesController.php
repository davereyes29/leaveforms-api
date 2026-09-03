<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexLeaveMonetizationCandidatesRequest;
use App\Http\Resources\LeaveMonetizationCandidatesResource;
use App\Services\Leave\LeaveMonetizationCandidatesService;

class AdminLeaveMonetizationCandidatesController extends Controller
{
    public function __construct(
        private readonly LeaveMonetizationCandidatesService $reportService,
    ) {}

    /**
     * GET /api/admin/reports/leave-monetization-candidates — excess VL/SL candidates (admin only).
     *
     * Query: optional year=YYYY (default: current Manila year)
     */
    public function __invoke(IndexLeaveMonetizationCandidatesRequest $request): LeaveMonetizationCandidatesResource
    {
        $report = $this->reportService->forAdmin(
            $request->employee(),
            (int) $request->validated('year'),
        );

        return LeaveMonetizationCandidatesResource::make($report);
    }
}
