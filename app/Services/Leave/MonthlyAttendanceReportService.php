<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin Monthly Attendance Report — confirmed leave days, not balances.
 *
 * Statuses: confirmed, converted_to_sl.
 * pending_approval / pending_conversion / rejected / cancelled are omitted.
 */
class MonthlyAttendanceReportService
{
    /** @var list<LeaveStatus> */
    public const STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::ConvertedToSl,
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveDurationService $durationService,
        private readonly MonthlyAttendanceMatrix $matrix,
    ) {}

    /**
     * @param  list<int>  $empIds
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function forAdmin(
        Employee $admin,
        string $month,
        MonthlyAttendanceCutOff $cutOff,
        array $empIds,
        ?int $groupId,
    ): array {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view the monthly attendance report.');
        }

        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        [$start, $end] = $this->matrix->period($month, $cutOff);

        $employees = $this->employeesForReport($empIds, $groupId);
        $group = $groupId !== null ? Group::query()->find($groupId) : null;
        $leaves = $this->leavesForPeriod($empIds, $start, $end);

        $holidayFrom = $start;
        $holidayTo = $end;

        foreach ($leaves as $leave) {
            $leaveStart = $leave->start_date->toDateString();
            $leaveEnd = $leave->end_date->toDateString();
            $holidayFrom = $leaveStart < $holidayFrom ? $leaveStart : $holidayFrom;
            $holidayTo = $leaveEnd > $holidayTo ? $leaveEnd : $holidayTo;
        }

        $holidayDates = $this->durationService->holidayDatesBetween($holidayFrom, $holidayTo);
        $cellsByEmp = $this->cellsByEmployee($leaves, $start, $end, $holidayDates);

        $rows = [];
        $rowTotals = [];

        foreach ($employees as $employee) {
            $cells = $this->matrix->mergeCells($cellsByEmp[(int) $employee->id] ?? []);
            $totals = $this->matrix->totalsForCells($cells);
            $rowTotals[] = $totals;

            $rows[] = [
                'employee' => $this->employeePayload($employee),
                'cells' => $cells,
                'totals' => $totals,
            ];
        }

        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        return [
            'month' => $month,
            'month_label' => $monthStart->format('F Y'),
            'cut_off' => $cutOff->value,
            'cut_off_label' => $cutOff->label(),
            'group' => $this->groupPayload($group),
            'employee_count' => count($rows),
            'generated_at' => Carbon::now()->toIso8601String(),
            'period' => [
                'start_date' => $start,
                'end_date' => $end,
            ],
            'dates' => $this->matrix->dateColumns($start, $end, $holidayDates),
            'rows' => $rows,
            'grand_totals' => $this->matrix->grandTotals($rowTotals),
        ];
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function employeesForReport(array $empIds, ?int $groupId): Collection
    {
        $employees = Employee::query()
            ->with('group')
            ->whereIn('id', $empIds)
            ->get()
            ->keyBy(fn (Employee $employee): int => (int) $employee->id);

        $found = $employees->keys()->map(fn ($id): int => (int) $id)->all();
        $missing = array_values(array_diff($empIds, $found));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => ['One or more employees were not found.'],
            ]);
        }

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);

            if ($groupId !== null && (int) $employee->group_id !== $groupId) {
                throw ValidationException::withMessages([
                    'emp_ids' => ['One or more employees do not belong to the selected group.'],
                ]);
            }
        }

        return $employees
            ->sortBy(fn (Employee $employee): string => mb_strtolower($employee->fullName()), SORT_NATURAL)
            ->values();
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, LeaveInfo>
     */
    private function leavesForPeriod(array $empIds, string $start, string $end): Collection
    {
        return LeaveInfo::query()
            ->with(['leaveType'])
            ->whereIn('emp_id', $empIds)
            ->whereIn('status', self::STATUSES)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, LeaveInfo>  $leaves
     * @param  list<string>  $holidayDates
     * @return array<int, list<array{date: string, category: string, amount: string, half: string|null, is_non_working: bool}>>
     */
    private function cellsByEmployee(Collection $leaves, string $start, string $end, array $holidayDates): array
    {
        $cellsByEmp = [];

        foreach ($leaves as $leave) {
            $empId = (int) $leave->emp_id;
            $code = strtoupper((string) ($leave->leaveType?->code ?? ''));

            $cellsByEmp[$empId] = array_merge(
                $cellsByEmp[$empId] ?? [],
                $this->matrix->cellsForLeave(
                    $code,
                    $leave->day_type,
                    $leave->start_date->toDateString(),
                    $leave->end_date->toDateString(),
                    (string) $leave->paid_days,
                    $start,
                    $end,
                    $holidayDates,
                ),
            );
        }

        return $cellsByEmp;
    }

    /**
     * @return array{id: int, employee_number: string, name: string, group: ?array{id: int, abbreviation: string|null, name: string|null}}
     */
    private function employeePayload(Employee $employee): array
    {
        $group = $employee->group;

        return [
            'id' => (int) $employee->id,
            'employee_number' => (string) $employee->id,
            'name' => $employee->fullName(),
            'group' => $group === null ? null : [
                'id' => (int) $group->id,
                'abbreviation' => $group->abbreviation,
                'name' => $group->name,
            ],
        ];
    }

    /**
     * @return array{id: int, abbreviation: string|null, name: string|null, dept_id: int}|null
     */
    private function groupPayload(?Group $group): ?array
    {
        if ($group === null) {
            return null;
        }

        return [
            'id' => (int) $group->id,
            'abbreviation' => $group->abbreviation,
            'name' => $group->name,
            'dept_id' => (int) $group->dept_id,
        ];
    }
}
