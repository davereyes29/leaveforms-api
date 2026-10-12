<?php

namespace App\Http\Controllers;

use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Http\Requests\Leave\IndexMonthlyAttendanceReportRequest;
use App\Http\Requests\Leave\IndexTeamMonthlyAttendanceEmployeesRequest;
use App\Http\Resources\AdminEmployeeRefResource;
use App\Http\Resources\MonthlyAttendanceReportResource;
use App\Services\Leave\MonthlyAttendanceReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamMonthlyAttendanceReportController extends Controller
{
    public function __construct(
        private readonly MonthlyAttendanceReportService $reportService,
    ) {}

    /**
     * GET /api/team/reports/monthly-attendance — confirmed leave matrix for the viewer's team.
     *
     * PIC: designated formspic groups only. President: active P4 employees (group_id ignored).
     *
     * Query: month=YYYY-MM, cut_off=monthly|first_half|second_half, optional emp_ids[]=, optional group_id
     */
    public function show(IndexMonthlyAttendanceReportRequest $request): MonthlyAttendanceReportResource
    {
        $groupId = $request->validated('group_id');
        $empIds = $request->validated('emp_ids');

        $report = $this->reportService->forApprover(
            $request->employee(),
            $request->validated('month'),
            $request->enum('cut_off', MonthlyAttendanceCutOff::class),
            is_array($empIds) ? array_map('intval', $empIds) : [],
            $groupId !== null ? (int) $groupId : null,
        );

        return MonthlyAttendanceReportResource::make($report);
    }

    /**
     * GET /api/team/reports/monthly-attendance/employees — picker rows for the team report.
     *
     * Optional query: ?group_id=16 (ignored for the president).
     */
    public function employees(IndexTeamMonthlyAttendanceEmployeesRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');

        $employees = $this->reportService->employeesForApprover(
            $request->employee(),
            $groupId !== null ? (int) $groupId : null,
        );

        return AdminEmployeeRefResource::collection($employees);
    }
}
