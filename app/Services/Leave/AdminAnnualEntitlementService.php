<?php

namespace App\Services\Leave;

use App\Enums\Leave\BalanceTransactionReason;
use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin annual VL/SL distribution: candidate list and confirm (batch grant).
 *
 * Tenure is complete years from date_hired to 1 January of the grant year
 * (Asia/Manila). Eligible = active regulars with a hire date on or before that day.
 */
class AdminAnnualEntitlementService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * @param  list<int>|null  $empIds
     * @return array{paginator: LengthAwarePaginator<int, Employee>, summary: array{year: int, as_of: string, eligible_count: int, granted_count: int, pending_count: int}}
     *
     * @throws AuthorizationException
     */
    public function paginateForAdmin(
        Employee $admin,
        int $year,
        int $perPage,
        ?int $groupId = null,
        ?string $q = null,
        string $sort = 'asc',
        ?array $empIds = null,
    ): array {
        $this->assertAdmin($admin, 'view annual entitlement candidates');

        $asOf = $this->asOfDate($year);
        [$vlType, $slType] = $this->vlAndSlTypes();

        $query = $this->eligibleQuery($asOf, $groupId, $q, $empIds);

        $eligibleIds = $query->clone()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $grantedEmpIds = $this->fullyGrantedEmpIds($eligibleIds, $year, $vlType->id, $slType->id);
        $grantedCount = count($grantedEmpIds);

        $direction = $sort === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->with('group.department')
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->attachEntitlements(
            collect($paginator->items()),
            $asOf,
            $vlType,
            $slType,
            $grantedEmpIds,
        );

        return [
            'paginator' => $paginator,
            'summary' => [
                'year' => $year,
                'as_of' => $asOf->toDateString(),
                'eligible_count' => count($eligibleIds),
                'granted_count' => $grantedCount,
                'pending_count' => count($eligibleIds) - $grantedCount,
            ],
        ];
    }

    /**
     * Grant VL/SL annual entitlement for the checked employees. All-or-nothing.
     *
     * @param  list<int>  $empIds
     * @return array{employees: Collection<int, Employee>, year: int, as_of: string, granted_count: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function distributeForAdmin(Employee $admin, int $year, array $empIds): array
    {
        $this->assertAdmin($admin, 'distribute annual entitlements');

        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        sort($empIds);

        $asOf = $this->asOfDate($year);
        [$vlType, $slType] = $this->vlAndSlTypes();

        $employees = $this->loadAndValidateTargets($empIds, $asOf, $year, $vlType->id, $slType->id);

        $remarks = 'Annual entitlement '.$year;

        DB::transaction(function () use ($employees, $asOf, $year, $vlType, $slType, $remarks): void {
            foreach ($employees as $employee) {
                $empId = (int) $employee->id;
                $this->balanceService->lockOrCreateBalance($empId, (int) $vlType->id);
                $this->balanceService->lockOrCreateBalance($empId, (int) $slType->id);

                $types = $this->grantedTypesForEmp($empId, $year, (int) $vlType->id, (int) $slType->id);
                $vlDone = $types['vl'];
                $slDone = $types['sl'];

                if ($vlDone && $slDone) {
                    throw ValidationException::withMessages([
                        'emp_ids' => [
                            'Annual entitlement for '.$year.' was already granted to employee '.$empId.'.',
                        ],
                    ]);
                }

                $years = $this->yearsOfService($employee, $asOf);
                $vlGrant = $this->vlGrant();
                $slGrant = $this->slGrant($years);

                if (! $vlDone) {
                    $this->balanceService->applyAnnualEntitlement($empId, (int) $vlType->id, $vlGrant, $remarks);
                }

                if (! $slDone) {
                    $this->balanceService->applyAnnualEntitlement($empId, (int) $slType->id, $slGrant, $remarks);
                }
            }
        });

        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $empIds)
            ->orderBy('id')
            ->get();

        $this->attachEntitlements(
            $employees,
            $asOf,
            $vlType,
            $slType,
            $empIds,
        );

        return [
            'employees' => $employees,
            'year' => $year,
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

    private function asOfDate(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 1, 0, 0, 0, $this->timezone())->startOfDay();
    }

    /**
     * Ledger window for the Manila calendar year, as UTC datetimes (app timezone is UTC).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function yearWindowUtc(int $year): array
    {
        $tz = $this->timezone();
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $tz)->utc();
        $end = CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, $tz)->utc();

        return [$start, $end];
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
    private function eligibleQuery(CarbonImmutable $asOf, ?int $groupId, ?string $q, ?array $empIds = null): Builder
    {
        $probationary = EmploymentStatus::designationIds('probationary_designation_ids');
        $contractual = EmploymentStatus::designationIds('contractual_designation_ids');
        $excluded = array_values(array_unique([...$probationary, ...$contractual]));

        $query = Employee::query()
            ->where('emp_status', 1)
            ->whereNotNull('date_hired')
            ->whereDate('date_hired', '<=', $asOf->toDateString())
            ->whereNotIn('designation', $excluded);

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
     * @param  list<int>  $empIds
     * @return list<int>
     */
    private function fullyGrantedEmpIds(array $empIds, int $year, int $vlTypeId, int $slTypeId): array
    {
        if ($empIds === []) {
            return [];
        }

        $byEmp = $this->grantedTypesByEmp($empIds, $year, $vlTypeId, $slTypeId);
        $fullyGranted = [];

        foreach ($byEmp as $empId => $types) {
            if ($types['vl'] && $types['sl']) {
                $fullyGranted[] = (int) $empId;
            }
        }

        return $fullyGranted;
    }

    /**
     * @param  list<int>  $empIds
     * @return array<int, array{vl: bool, sl: bool}>
     */
    private function grantedTypesByEmp(array $empIds, int $year, int $vlTypeId, int $slTypeId): array
    {
        if ($empIds === []) {
            return [];
        }

        [$start, $end] = $this->yearWindowUtc($year);

        $rows = LeaveBalanceTransaction::query()
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get(['emp_id', 'leave_type_id']);

        $byEmp = [];

        foreach ($empIds as $empId) {
            $byEmp[$empId] = ['vl' => false, 'sl' => false];
        }

        foreach ($rows as $row) {
            $empId = (int) $row->emp_id;

            if ((int) $row->leave_type_id === $vlTypeId) {
                $byEmp[$empId]['vl'] = true;
            }

            if ((int) $row->leave_type_id === $slTypeId) {
                $byEmp[$empId]['sl'] = true;
            }
        }

        return $byEmp;
    }

    /**
     * @return array{vl: bool, sl: bool}
     */
    private function grantedTypesForEmp(int $empId, int $year, int $vlTypeId, int $slTypeId): array
    {
        $map = $this->grantedTypesByEmp([$empId], $year, $vlTypeId, $slTypeId);

        return $map[$empId] ?? ['vl' => false, 'sl' => false];
    }

    /**
     * @param  list<int>  $empIds
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function loadAndValidateTargets(
        array $empIds,
        CarbonImmutable $asOf,
        int $year,
        int $vlTypeId,
        int $slTypeId,
    ): Collection {
        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $empIds)
            ->get()
            ->keyBy('id');

        $missing = [];
        $ineligible = [];

        foreach ($empIds as $empId) {
            $employee = $employees->get($empId);

            if ($employee === null) {
                $missing[] = $empId;

                continue;
            }

            if (! $this->isEligibleRegular($employee, $asOf)) {
                $ineligible[] = $empId;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => ['The selected employee does not exist: '.implode(', ', $missing).'.'],
            ]);
        }

        if ($ineligible !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => [
                    'The selected employees are not eligible for '.$year
                    .' (inactive, not regular, missing hire date, or hired after 1 January): '
                    .implode(', ', $ineligible).'.',
                ],
            ]);
        }

        $fullyGranted = $this->fullyGrantedEmpIds($empIds, $year, $vlTypeId, $slTypeId);

        if ($fullyGranted !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => [
                    'Annual entitlement for '.$year.' was already granted to: '
                    .implode(', ', $fullyGranted).'.',
                ],
            ]);
        }

        return $employees->sortBy('id')->values();
    }

    private function isEligibleRegular(Employee $employee, CarbonImmutable $asOf): bool
    {
        if (! $employee->isActive() || $employee->date_hired === null) {
            return false;
        }

        if ($employee->date_hired->toDateString() > $asOf->toDateString()) {
            return false;
        }

        return EmploymentStatus::fromDesignationId((int) $employee->designation) === EmploymentStatus::Regular;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  list<int>  $fullyGrantedEmpIds
     */
    private function attachEntitlements(
        Collection $employees,
        CarbonImmutable $asOf,
        LeaveType $vlType,
        LeaveType $slType,
        array $fullyGrantedEmpIds,
    ): void {
        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        $grantedSet = array_fill_keys($fullyGrantedEmpIds, true);
        $vlTypeId = (int) $vlType->id;
        $slTypeId = (int) $slType->id;

        $balancesByEmp = LeaveBalance::query()
            ->whereIn('emp_id', $employees->pluck('id')->all())
            ->whereIn('leave_type_id', [$vlTypeId, $slTypeId])
            ->get()
            ->groupBy(fn (LeaveBalance $row): int => (int) $row->emp_id);

        foreach ($employees as $employee) {
            $years = $this->yearsOfService($employee, $asOf);
            $vlGrant = $this->vlGrant();
            $slGrant = $this->slGrant($years);
            $alreadyGranted = isset($grantedSet[(int) $employee->id]);

            $byType = ($balancesByEmp->get((int) $employee->id) ?? collect())
                ->keyBy('leave_type_id');

            $vlAvailable = $this->decimalString($byType->get($vlTypeId)?->available);
            $slAvailable = $this->decimalString($byType->get($slTypeId)?->available);

            $employee->setAttribute('years_of_service', $years);
            $employee->setAttribute('vl_available', $vlAvailable);
            $employee->setAttribute('sl_available', $slAvailable);
            $employee->setAttribute('vl_grant', $vlGrant);
            $employee->setAttribute('sl_grant', $slGrant);
            $employee->setAttribute(
                'vl_after',
                $alreadyGranted ? $vlAvailable : bcadd($vlAvailable, $vlGrant, 2),
            );
            $employee->setAttribute(
                'sl_after',
                $alreadyGranted ? $slAvailable : bcadd($slAvailable, $slGrant, 2),
            );
            $employee->setAttribute('already_granted', $alreadyGranted);
        }
    }

    private function yearsOfService(Employee $employee, CarbonImmutable $asOf): int
    {
        if ($employee->date_hired === null) {
            return 0;
        }

        $hired = CarbonImmutable::parse($employee->date_hired->toDateString(), $this->timezone())->startOfDay();

        if ($hired->gt($asOf)) {
            return 0;
        }

        $years = $asOf->year - $hired->year;

        if ($asOf->month < $hired->month
            || ($asOf->month === $hired->month && $asOf->day < $hired->day)) {
            $years--;
        }

        return max(0, $years);
    }

    private function vlGrant(): string
    {
        return bcadd((string) config('leave.annual_entitlement.vl', '15.00'), '0', 2);
    }

    private function slGrant(int $yearsOfService): string
    {
        $threshold = (int) config('leave.annual_entitlement.tenure_years', 3);

        $amount = $yearsOfService >= $threshold
            ? (string) config('leave.annual_entitlement.sl_at_or_over', '18.00')
            : (string) config('leave.annual_entitlement.sl_under', '15.00');

        return bcadd($amount, '0', 2);
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
