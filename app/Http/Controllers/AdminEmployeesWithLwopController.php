<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexEmployeesWithLwopRequest;
use App\Http\Resources\EmployeesWithLwopReportResource;
use App\Services\Leave\EmployeesWithLwopReportService;

class AdminEmployeesWithLwopController extends Controller
{
    public function __construct(
        private readonly EmployeesWithLwopReportService $reportService,
    ) {}

    /**
     * GET /api/admin/reports/employees-with-lwop — VL/SL/LL unpaid days (admin only).
     *
     * Query: optional year=YYYY (default: current Manila year), optional group_id
     */
    public function __invoke(IndexEmployeesWithLwopRequest $request): EmployeesWithLwopReportResource
    {
        $groupId = $request->validated('group_id');

        $report = $this->reportService->forAdmin(
            $request->employee(),
            (int) $request->validated('year'),
            $groupId !== null ? (int) $groupId : null,
        );

        return EmployeesWithLwopReportResource::make($report);
    }
}
