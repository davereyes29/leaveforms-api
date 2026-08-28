<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveType;
use App\Services\Kdtph\EmployeeDesignationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin contractual VL/SL distribution: candidate list and confirm (batch grant).
 *
 * Eligible = active contractuals. Amounts are admin-typed (positive, ≤ max).
 * Grants may be repeated (no yearly idempotency).
 */
class AdminContractualEntitlementService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveBalanceService $balanceService,
        private readonly EmployeeDesignationResolver $designationResolver,
    ) {}

    /**
     * @param  list<int>|null  $empIds
     * @return array{paginator: LengthAwarePaginator<int, Employee>, summary: array{as_of: string, eligible_count: int}}
     *
     * @throws AuthorizationException
     */
    public function paginateForAdmin(
        Employee $admin,
        int $perPage,
        ?int $groupId = null,
        ?string $q = null,
        string $sort = 'asc',
        ?array $empIds = null,
    ): array {
        $this->assertAdmin($admin, 'view contractual entitlement candidates');

        $asOf = $this->asOfToday();
        [$vlType, $slType] = $this->vlAndSlTypes();

        $query = $this->eligibleQuery($groupId, $q, $empIds);

        $eligibleCount = (clone $query)->count();
        $direction = $sort === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->with('group.department')
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->attachBalances(
            collect($paginator->items()),
            $vlType,
            $slType,
        );

        return [
            'paginator' => $paginator,
            'summary' => [
                'as_of' => $asOf->toDateString(),
                'eligible_count' => $eligibleCount,
            ],
        ];
    }

    /**
     * Grant admin-typed VL and/or SL for contractual employees. All-or-nothing.
     *
     * @param  list<array{emp_id: int, vl_to_add?: string|null, sl_to_add?: string|null}>  $rows
     * @return array{employees: Collection<int, Employee>, as_of: string, granted_count: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function distributeForAdmin(Employee $admin, array $rows): array
    {
        $this->assertAdmin($admin, 'distribute contractual entitlements');

        $asOf = $this->asOfToday();
        [$vlType, $slType] = $this->vlAndSlTypes();
        $normalized = $this->normalizeAndValidateRows($rows);
        $empIds = array_column($normalized, 'emp_id');

        $employees = $this->loadAndValidateTargets($empIds);
        $byId = $employees->keyBy('id');

        $remarks = (string) config('leave.contractual_entitlement.remarks', 'Contractual leave credits');

        DB::transaction(function () use ($admin, $normalized, $byId, $vlType, $slType, $remarks): void {
            $vlRecipients = [];
            $slRecipients = [];

            foreach ($normalized as $row) {
                $empId = $row['emp_id'];
                $employee = $byId->get($empId);

                if ($employee === null) {
                    continue;
                }

                if ($row['vl_to_add'] !== null) {
                    $this->balanceService->lockOrCreateBalance($empId, (int) $vlType->id);
                    $this->balanceService->applyContractualEntitlement(
                        $empId,
                        (int) $vlType->id,
                        $row['vl_to_add'],
                        $remarks,
                    );
                    $vlRecipients[] = $empId;
                }

                if ($row['sl_to_add'] !== null) {
                    $this->balanceService->lockOrCreateBalance($empId, (int) $slType->id);
                    $this->balanceService->applyContractualEntitlement(
                        $empId,
                        (int) $slType->id,
                        $row['sl_to_add'],
                        $remarks,
                    );
                    $slRecipients[] = $empId;
                }
            }

            LeaveActivityHistory::recordBatchCreditDistribution(
                (int) $admin->id,
                ActivityType::ContractualCreditsDistributed,
                (int) $vlType->id,
                $vlRecipients,
                $remarks,
            );
            LeaveActivityHistory::recordBatchCreditDistribution(
                (int) $admin->id,
                ActivityType::ContractualCreditsDistributed,
                (int) $slType->id,
                $slRecipients,
                $remarks,
            );
        });

        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $empIds)
            ->orderBy('id')
            ->get();

        $grantsByEmp = [];
        foreach ($normalized as $row) {
            $grantsByEmp[$row['emp_id']] = [
                'vl_grant' => $row['vl_to_add'] ?? '0.00',
                'sl_grant' => $row['sl_to_add'] ?? '0.00',
            ];
        }

        $this->attachBalances($employees, $vlType, $slType, $grantsByEmp);

        return [
            'employees' => $employees,
            'as_of' => $asOf->toDateString(),
            'granted_count' => $employees->count(),
        ];
    }

    private function assertAdmin(Employee $admin, string $action): void
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can '.$action.'.');
        }
    }

    private function timezone(): string
    {
        return (string) config('leave.reminders.timezone', 'Asia/Manila');
    }

    private function asOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * @return array{0: LeaveType, 1: LeaveType}
     */
    private function vlAndSlTypes(): array
    {
        $types = LeaveType::query()
            ->whereIn('code', ['VL', 'SL'])
            ->get()
            ->keyBy(fn (LeaveType $type): string => strtoupper((string) $type->code));

        $vl = $types->get('VL');
        $sl = $types->get('SL');

        if ($vl === null || $sl === null) {
            throw new RuntimeException('VL and SL leave types must exist.');
        }

        return [$vl, $sl];
    }

    /**
     * @param  list<int>|null  $empIds
     * @return Builder<Employee>
     */
    private function eligibleQuery(?int $groupId, ?string $q, ?array $empIds = null): Builder
    {
        $query = Employee::query()
            ->where('emp_status', 1)
            ->whereEmploymentStatus(EmploymentStatus::Contractual);

        if ($empIds !== null) {
            $query->whereIn('id', $empIds);
        }

        if ($groupId !== null) {
            $scopedEmpIds = $this->approverResolver->employeeIdsInEffectiveGroups(
                [$groupId],
                activeOnly: true,
            );

            if ($scopedEmpIds === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('id', $scopedEmpIds);
            }
        }

        $this->applySearch($query, $q);

        return $query;
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{emp_id: int, vl_to_add: ?string, sl_to_add: ?string}>
     *
     * @throws ValidationException
     */
    private function normalizeAndValidateRows(array $rows): array
    {
        $max = bcadd((string) config('leave.contractual_entitlement.max_per_type', '15.00'), '0', 2);
        $seen = [];
        $normalized = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $empId = (int) ($row['emp_id'] ?? 0);
            $key = 'employees.'.$index;

            if ($empId < 1) {
                $errors[$key.'.emp_id'] = ['The emp_id field is required.'];

                continue;
            }

            if (isset($seen[$empId])) {
                $errors[$key.'.emp_id'] = ['Duplicate emp_id in the employees list.'];

                continue;
            }

            $seen[$empId] = true;

            $vl = $this->optionalPositiveAmount($row['vl_to_add'] ?? null, $max, $key.'.vl_to_add', $errors);
            $sl = $this->optionalPositiveAmount($row['sl_to_add'] ?? null, $max, $key.'.sl_to_add', $errors);

            if ($vl === null && $sl === null && ! isset($errors[$key.'.vl_to_add']) && ! isset($errors[$key.'.sl_to_add'])) {
                $errors[$key] = ['Provide vl_to_add and/or sl_to_add greater than 0 (max '.$max.').'];
            }

            $normalized[] = [
                'emp_id' => $empId,
                'vl_to_add' => $vl,
                'sl_to_add' => $sl,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        usort($normalized, fn (array $a, array $b): int => $a['emp_id'] <=> $b['emp_id']);

        return $normalized;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function optionalPositiveAmount(mixed $value, string $max, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            $errors[$field] = ['The amount must be a number.'];

            return null;
        }

        $amount = bcadd((string) $value, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            $errors[$field] = ['The amount must be greater than 0.'];

            return null;
        }

        if (bccomp($amount, $max, 2) > 0) {
            $errors[$field] = ['The amount may not be greater than '.$max.'.'];

            return null;
        }

        // Reject more than 2 decimal places in the original input when string-like.
        if (is_string($value) && preg_match('/\.\d{3,}/', $value) === 1) {
            $errors[$field] = ['The amount may have at most 2 decimal places.'];

            return null;
        }

        return $amount;
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function loadAndValidateTargets(array $empIds): Collection
    {
        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $empIds)
            ->get()
            ->keyBy('id');

        $this->designationResolver->hydrate($employees);

        $missing = [];
        $ineligible = [];

        foreach ($empIds as $empId) {
            $employee = $employees->get($empId);

            if ($employee === null) {
                $missing[] = $empId;

                continue;
            }

            if (! $this->isEligibleContractual($employee)) {
                $ineligible[] = $empId;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'employees' => ['The selected employee does not exist: '.implode(', ', $missing).'.'],
            ]);
        }

        if ($ineligible !== []) {
            throw ValidationException::withMessages([
                'employees' => [
                    'The selected employees are not active contractuals: '
                    .implode(', ', $ineligible).'.',
                ],
            ]);
        }

        return $employees->sortBy('id')->values();
    }

    private function isEligibleContractual(Employee $employee): bool
    {
        if (! $employee->isActive()) {
            return false;
        }

        return $employee->employmentStatus() === EmploymentStatus::Contractual;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, array{vl_grant: string, sl_grant: string}>|null  $grantsByEmp
     */
    private function attachBalances(
        Collection $employees,
        LeaveType $vlType,
        LeaveType $slType,
        ?array $grantsByEmp = null,
    ): void {
        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        $vlTypeId = (int) $vlType->id;
        $slTypeId = (int) $slType->id;

        $balancesByEmp = LeaveBalance::query()
            ->whereIn('emp_id', $employees->pluck('id')->all())
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->get()
            ->groupBy(fn (LeaveBalance $row): int => (int) $row->emp_id);

        foreach ($employees as $employee) {
            $byType = ($balancesByEmp->get((int) $employee->id) ?? collect())
                ->keyBy('leave_type_id');

            $vlAvailable = $this->decimalString($byType->get($vlTypeId)?->available);
            $slAvailable = $this->decimalString($byType->get($slTypeId)?->available);

            $employee->setAttribute('vl_available', $vlAvailable);
            $employee->setAttribute('sl_available', $slAvailable);

            if ($grantsByEmp !== null) {
                $grants = $grantsByEmp[(int) $employee->id] ?? ['vl_grant' => '0.00', 'sl_grant' => '0.00'];
                $vlGrant = $grants['vl_grant'];
                $slGrant = $grants['sl_grant'];

                // After POST, available already includes the grant. Expose to_add and
                // before = available − grant for the confirm response.
                $vlBefore = bcsub($vlAvailable, $vlGrant, 2);
                $slBefore = bcsub($slAvailable, $slGrant, 2);

                $employee->setAttribute('vl_before', $vlBefore);
                $employee->setAttribute('sl_before', $slBefore);
                $employee->setAttribute('vl_grant', $vlGrant);
                $employee->setAttribute('sl_grant', $slGrant);
                $employee->setAttribute('vl_after', $vlAvailable);
                $employee->setAttribute('sl_after', $slAvailable);
            }
        }
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
