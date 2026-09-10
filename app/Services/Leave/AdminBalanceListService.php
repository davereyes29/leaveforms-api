<?php

namespace App\Services\Leave;

use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveType;
use App\Services\Kdtph\EmployeeDesignationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Org-wide employee leave balances for LEAVE_ADMIN_EMP_ID.
 *
 * Active employees only. Optional department / group / employment-status / name-or-number search.
 * Employment status uses emp_prof.fldDesig (fallback: employee_list.designation).
 * Department and group use the employee's effective primary group
 * (employee_list.group_id, or LEAVE_EMPLOYEE_GROUP_OVERRIDES).
 * POST adjustments change available only (reserved is left as-is) for VL, SL, EL, PTB.
 * ML/PL are grant-on-submit and cannot be adjusted; LL/SPL are not credit-grid types.
 */
class AdminBalanceListService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveBalanceService $balanceService,
        private readonly EmployeeDesignationResolver $designationResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Employee>
     *
     * @throws AuthorizationException
     */
    public function paginateForAdmin(
        Employee $admin,
        int $perPage,
        ?int $departmentId = null,
        ?int $groupId = null,
        ?EmploymentStatus $employmentStatus = null,
        ?string $q = null,
        string $sort = 'asc',
    ): LengthAwarePaginator {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view employee balances.');
        }

        $query = Employee::query()
            ->with('group.department')
            ->where('emp_status', 1);

        $scopedEmpIds = $this->empIdsForOrgFilters($departmentId, $groupId);

        if ($scopedEmpIds !== null) {
            if ($scopedEmpIds === []) {
                return Employee::query()->whereRaw('0 = 1')->paginate($perPage);
            }

            $query->whereIn('id', $scopedEmpIds);
        }

        if ($employmentStatus !== null) {
            $query->whereEmploymentStatus($employmentStatus);
        }

        $this->applySearch($query, $q);

        $direction = $sort === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->attachBalances(collect($paginator->items()));

        return $paginator;
    }

    /**
     * Apply a signed available-credit correction for one employee and leave type.
     *
     * Reserved is unchanged. Writes a manual_adjustment ledger row and a solo
     * admin-log activity.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function adjustForAdmin(
        Employee $admin,
        int $empId,
        string $code,
        string $availableDelta,
        string $remarks,
    ): Employee {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can adjust employee balances.');
        }

        $employee = Employee::query()
            ->with('group.department')
            ->find($empId);

        if ($employee === null) {
            throw ValidationException::withMessages([
                'emp_id' => ['The selected employee does not exist.'],
            ]);
        }

        if (! $employee->isActive()) {
            throw ValidationException::withMessages([
                'emp_id' => ['The selected employee is not active.'],
            ]);
        }

        $codeRejection = LeaveTypeRules::manualAdjustmentRejection($code);

        if ($codeRejection !== null) {
            throw ValidationException::withMessages([
                'code' => [$codeRejection],
            ]);
        }

        if (! $this->isApplicable($code, $employee)) {
            throw ValidationException::withMessages([
                'code' => ['This leave type is not applicable to this employee.'],
            ]);
        }

        $leaveType = LeaveType::query()->where('code', $code)->firstOrFail();
        $delta = bcadd($availableDelta, '0', 2);

        return DB::transaction(function () use ($admin, $employee, $leaveType, $delta, $remarks): Employee {
            try {
                $this->balanceService->applyManualAdjustment(
                    (int) $employee->id,
                    (int) $leaveType->id,
                    $delta,
                    $remarks,
                );
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'available_delta' => [$exception->getMessage()],
                ]);
            }

            LeaveActivityHistory::recordSoloCreditAdjustment(
                (int) $admin->id,
                (int) $employee->id,
                (int) $leaveType->id,
                $remarks,
            );

            $this->attachBalances(collect([$employee]));

            return $employee;
        });
    }

    /**
     * @return list<int>|null null = no org-scope filter (entire active org)
     */
    private function empIdsForOrgFilters(?int $departmentId, ?int $groupId): ?array
    {
        if ($departmentId === null && $groupId === null) {
            return null;
        }

        if ($groupId !== null) {
            return $this->approverResolver->employeeIdsInEffectiveGroups(
                [$groupId],
                activeOnly: true,
            );
        }

        $groupIds = Group::query()
            ->where('dept_id', $departmentId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->approverResolver->employeeIdsInEffectiveGroups(
            $groupIds,
            activeOnly: true,
        );
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, ?string $q): void
    {
        $term = trim((string) $q);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('firstname', 'like', $like)
                ->orWhere('surname', 'like', $like)
                ->orWhereRaw("CONCAT(firstname, ' ', surname) LIKE ?", [$like])
                ->orWhereRaw('CAST(id AS CHAR) LIKE ?', [$like]);
        });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function attachBalances(Collection $employees): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $this->designationResolver->hydrate($employees);

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        $types = LeaveType::query()->orderBy('id')->get();

        $balancesByEmp = LeaveBalance::query()
            ->whereIn('emp_id', $employees->pluck('id')->all())
            ->get()
            ->groupBy(fn (LeaveBalance $row): int => (int) $row->emp_id);

        foreach ($employees as $employee) {
            $byType = ($balancesByEmp->get((int) $employee->id) ?? collect())
                ->keyBy('leave_type_id');

            $rows = $types->map(function (LeaveType $type) use ($employee, $byType): array {
                $applicable = $this->isApplicable($type->code, $employee);
                $row = $byType->get($type->id);

                return [
                    'leave_type_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->type,
                    'available' => $applicable ? $this->decimalString($row?->available) : '0.00',
                    'reserved' => $applicable ? $this->decimalString($row?->reserved) : '0.00',
                    'applicable' => $applicable,
                ];
            })->values()->all();

            $employee->setAttribute(
                'employment_status',
                $employee->employmentStatus()->value,
            );
            $employee->setAttribute('balance_rows', $rows);
        }
    }

    private function isApplicable(string $code, Employee $employee): bool
    {
        return match (strtoupper($code)) {
            'ML' => $employee->isFemale(),
            'PL' => $employee->isMale(),
            'SPL' => false,
            default => true,
        };
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
