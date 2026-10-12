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
 * Monthly Attendance Report — confirmed leave days, not balances.
 *
 * Admin: every active employee, with an optional group.
 * Approver: active employees in the viewer's designated formspic groups.
 * President: active P4 employees; designated groups are ignored.
 *
 * Statuses: confirmed, converted_to_sl, converted_to_el.
 * pending_approval / pending_conversion / rejected / cancelled are omitted.
 */
class MonthlyAttendanceReportService
{
    /** @var list<LeaveStatus> */
    public const STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::ConvertedToSl,
        LeaveStatus::ConvertedToEl,
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveDurationService $durationService,
        private readonly MonthlyAttendanceMatrix $matrix,
        private readonly SpecialLeavePolicy $specialLeavePolicy,
    ) {}

    /**
     * @param  list<int>  $empIds  Empty means every active employee (optional group filter).
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
        $employees = $this->employeesForReport($empIds, $groupId);
        $group = $groupId !== null ? Group::query()->find($groupId) : null;

        return $this->assemble($employees, $month, $cutOff, $group);
    }

    /**
     * Confirmed-leave matrix for an approver's team.
     *
     * PIC: active employees whose effective group is in the viewer's formspic groups.
     * President: active P4 employees (formspic.fldRole = 4). group_id is ignored.
     *
     * @param  list<int>  $empIds  Empty means every employee in scope.
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function forApprover(
        Employee $approver,
        string $month,
        MonthlyAttendanceCutOff $cutOff,
        array $empIds,
        ?int $groupId,
    ): array {
        [$allowedIds, $group] = $this->scopeForApprover($approver, $groupId);

        return $this->assemble(
            $this->employeesByIds($this->selectedIdsWithinScope($empIds, $allowedIds)),
            $month,
            $cutOff,
            $group,
        );
    }

    /**
     * Active employees the approver may include on the team attendance report.
     * Same scope as {@see forApprover}: PIC groups, or every active P4 for the president.
     *
     * @return Collection<int, Employee>
     *
     * @throws AuthorizationException
     */
    public function employeesForApprover(Employee $approver, ?int $groupId): Collection
    {
        [$allowedIds] = $this->scopeForApprover($approver, $groupId);

        if ($allowedIds === []) {
            return collect();
        }

        $employees = Employee::query()
            ->with('group')
            ->whereIn('id', $allowedIds)
            ->orderBy('firstname')
            ->orderBy('surname')
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        return $employees;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function assemble(
        Collection $employees,
        string $month,
        MonthlyAttendanceCutOff $cutOff,
        ?Group $group,
    ): array {
        [$start, $end] = $this->matrix->period($month, $cutOff);
        $resolvedEmpIds = $employees
            ->map(fn (Employee $employee): int => (int) $employee->id)
            ->all();
        $leaves = $this->leavesForPeriod($resolvedEmpIds, $start, $end);

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
     * Active employees in scope, plus the group when a PIC narrowed to one group.
     * President scope is every active P4; the group argument is ignored.
     *
     * @return array{0: list<int>, 1: Group|null}
     *
     * @throws AuthorizationException
     */
    private function scopeForApprover(Employee $approver, ?int $groupId): array
    {
        $context = $this->approverResolver->contextFor((int) $approver->id);

        if (! $context->isPresident && $context->approvalLevel === null) {
            throw new AuthorizationException('Only approvers can view the team monthly attendance report.');
        }

        if ($context->isPresident) {
            return [
                $this->approverResolver->p4EmployeeIds(activeOnly: true),
                null,
            ];
        }

        if ($groupId !== null) {
            $group = Group::query()->find($groupId);

            if ($group === null || ! in_array($group->abbreviation, $context->groupCodes, true)) {
                throw new AuthorizationException(
                    'You are not allowed to view the monthly attendance report for this group.',
                );
            }

            return [
                $this->approverResolver->employeeIdsInEffectiveGroups([(int) $group->id], activeOnly: true),
                $group,
            ];
        }

        $groupIds = $this->groupIdsForCodes($context->groupCodes);

        return [
            $groupIds === []
                ? []
                : $this->approverResolver->employeeIdsInEffectiveGroups($groupIds, activeOnly: true),
            null,
        ];
    }

    /**
     * Empty selection means the whole scope. Any id outside the scope is rejected.
     *
     * @param  list<int>  $empIds
     * @param  list<int>  $allowedIds
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function selectedIdsWithinScope(array $empIds, array $allowedIds): array
    {
        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        $allowedIds = array_values(array_unique(array_map('intval', $allowedIds)));

        if ($empIds === []) {
            return $allowedIds;
        }

        $allowed = array_fill_keys($allowedIds, true);
        $outside = array_values(array_filter(
            $empIds,
            fn (int $id): bool => ! isset($allowed[$id]),
        ));

        if ($outside !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => ['One or more employees are outside your team.'],
            ]);
        }

        return $empIds;
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function employeesByIds(array $empIds): Collection
    {
        if ($empIds === []) {
            return collect();
        }

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
        }

        return $this->sortByEmployeeNumber($employees);
    }

    /**
     * @param  list<string>  $groupCodes
     * @return list<int>
     */
    private function groupIdsForCodes(array $groupCodes): array
    {
        if ($groupCodes === []) {
            return [];
        }

        return Group::query()
            ->whereIn('abbreviation', $groupCodes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function employeesForReport(array $empIds, ?int $groupId): Collection
    {
        if ($empIds === []) {
            return $this->allActiveEmployees($groupId);
        }

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

        return $this->sortByEmployeeNumber($employees);
    }

    /**
     * Same population as GET /admin/employees: active only, optional effective group.
     *
     * @return Collection<int, Employee>
     */
    private function allActiveEmployees(?int $groupId): Collection
    {
        $query = Employee::query()
            ->with('group')
            ->where('emp_status', 1);

        if ($groupId !== null) {
            $empIds = $this->approverResolver->employeeIdsInEffectiveGroups(
                [$groupId],
                activeOnly: true,
            );

            if ($empIds === []) {
                return collect();
            }

            $query->whereIn('id', $empIds);
        }

        $employees = $query->get();

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        return $this->sortByEmployeeNumber($employees);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    private function sortByEmployeeNumber(Collection $employees): Collection
    {
        return $employees
            ->sortBy(fn (Employee $employee): int => (int) $employee->id)
            ->values();
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, LeaveInfo>
     */
    private function leavesForPeriod(array $empIds, string $start, string $end): Collection
    {
        if ($empIds === []) {
            return collect();
        }

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
            $calendarDays = $code === 'SPL'
                ? $this->specialLeavePolicy->usesCalendarDaysForLeave($leave)
                : LeaveTypeRules::usesCalendarDays($code);

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
                    $calendarDays,
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
