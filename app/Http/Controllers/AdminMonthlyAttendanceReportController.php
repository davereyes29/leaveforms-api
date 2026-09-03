<?php

namespace App\Http\Controllers;

use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Http\Requests\Leave\IndexMonthlyAttendanceReportRequest;
use App\Http\Resources\MonthlyAttendanceReportResource;
use App\Services\Leave\MonthlyAttendanceReportService;

class AdminMonthlyAttendanceReportController extends Controller
{
    public function __construct(
        private readonly MonthlyAttendanceReportService $reportService,
    ) {}

    /**
     * GET /api/admin/reports/monthly-attendance — confirmed leave matrix (admin only).
     *
     * Query: month=YYYY-MM, cut_off=monthly|first_half|second_half, optional emp_ids[]=, optional group_id
     */
    public function __invoke(IndexMonthlyAttendanceReportRequest $request): MonthlyAttendanceReportResource
    {
        $groupId = $request->validated('group_id');
        $empIds = $request->validated('emp_ids');

        $report = $this->reportService->forAdmin(
            $request->employee(),
            $request->validated('month'),
            $request->enum('cut_off', MonthlyAttendanceCutOff::class),
            is_array($empIds) ? array_map('intval', $empIds) : [],
            $groupId !== null ? (int) $groupId : null,
        );

        return MonthlyAttendanceReportResource::make($report);
    }
}
