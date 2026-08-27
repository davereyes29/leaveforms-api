<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\BalanceTransactionReason;
use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
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
 * Admin newly-regularized distribution: candidate list and confirm (batch grant).
 *
 * Eligible = active, non-contractual, hired after 1 Jan of year, tenure
 * ≥ 6 months and < 12 months as of today (Asia/Manila), and no annual
 * entitlement for that year. Regularization date = date_hired + 6 months.
 * VL/SL = inclusive months from regularization month through December × rate.
 * PTB/EL are flat one-time grants.
 */
class AdminNewlyRegularizedService
{
    private const GRANT_CODES = ['VL', 'SL', 'PTB', 'EL'];

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
        $this->assertAdmin($admin, 'view newly regularized candidates');

        $asOf = $this->asOfToday();
        $types = $this->grantTypes();

        $query = $this->eligibleQuery($asOf, $year, $groupId, $q, $empIds);

        $eligibleIds = $query->clone()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $grantedEmpIds = $this->fullyGrantedEmpIds($eligibleIds, $year, $types);
        $grantedCount = count($grantedEmpIds);

        $direction = $sort === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->with('group.department')
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->attachEntitlements(
            collect($paginator->items()),
            $asOf,
            $year,
            $types,
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
     * Grant VL/SL/PTB/EL regularization entitlement. All-or-nothing.
     *
     * @param  list<int>  $empIds
     * @return array{employees: Collection<int, Employee>, year: int, as_of: string, granted_count: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function distributeForAdmin(Employee $admin, int $year, array $empIds): array
    {
        $this->assertAdmin($admin, 'distribute newly regularized entitlements');

        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        sort($empIds);

        $asOf = $this->asOfToday();
        $types = $this->grantTypes();

        $employees = $this->loadAndValidateTargets($empIds, $asOf, $year, $types);

        $remarks = 'Regularization entitlement '.$year;

        DB::transaction(function () use ($admin, $employees, $asOf, $year, $types, $remarks): void {
            $recipients = [
                'vl' => [],
                'sl' => [],
                'ptb' => [],
                'el' => [],
            ];

            foreach ($employees as $employee) {
                $empId = (int) $employee->id;

                foreach ($types as $type) {
                    $this->balanceService->lockOrCreateBalance($empId, (int) $type->id);
                }

                $done = $this->grantedTypesForEmp($empId, $year, $types);

                if ($done['vl'] && $done['sl'] && $done['ptb'] && $done['el']) {
                    throw ValidationException::withMessages([
                        'emp_ids' => [
                            'Regularization entitlement for '.$year.' was already granted to employee '.$empId.'.',
                        ],
                    ]);
                }

                $grants = $this->computeGrants($employee, $asOf, $year);

                if (! $done['vl']) {
                    $this->balanceService->applyRegularizationEntitlement(
                        $empId,
                        (int) $types['vl']->id,
                        $grants['vl_grant'],
                        $remarks,
                    );
                    $recipients['vl'][] = $empId;
                }

                if (! $done['sl']) {
                    $this->balanceService->applyRegularizationEntitlement(
                        $empId,
                        (int) $types['sl']->id,
                        $grants['sl_grant'],
                        $remarks,
                    );
                    $recipients['sl'][] = $empId;
                }

                if (! $done['ptb']) {
                    $this->balanceService->applyRegularizationEntitlement(
                        $empId,
                        (int) $types['ptb']->id,
                        $grants['ptb_grant'],
                        $remarks,
                    );
                    $recipients['ptb'][] = $empId;
                }

                if (! $done['el']) {
                    $this->balanceService->applyRegularizationEntitlement(
                        $empId,
                        (int) $types['el']->id,
                        $grants['el_grant'],
                        $remarks,
                    );
                    $recipients['el'][] = $empId;
                }
            }

            foreach (['vl', 'sl', 'ptb', 'el'] as $code) {
                LeaveActivityHistory::recordBatchCreditDistribution(
                    (int) $admin->id,
                    ActivityType::RegularizationCreditsDistributed,
                    (int) $types[$code]->id,
                    $recipients[$code],
                    $remarks,
                );
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
            $year,
            $types,
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

    private function asOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    private function yearStart(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 1, 0, 0, 0, $this->timezone())->startOfDay();
    }

    /**
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
     * @return array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}
     */
    private function grantTypes(): array
    {
        $types = LeaveType::query()
            ->whereIn('code', self::GRANT_CODES)
            ->get()
            ->keyBy(fn (LeaveType $type): string => strtolower((string) $type->code));

        foreach (['vl', 'sl', 'ptb', 'el'] as $key) {
            if (! $types->has($key)) {
                throw new RuntimeException(strtoupper($key).' leave type must exist.');
            }
        }

        return [
            'vl' => $types->get('vl'),
            'sl' => $types->get('sl'),
            'ptb' => $types->get('ptb'),
            'el' => $types->get('el'),
        ];
    }

    /**
     * @param  list<int>|null  $empIds
     * @return Builder<Employee>
     */
    private function eligibleQuery(
        CarbonImmutable $asOf,
        int $year,
        ?int $groupId,
        ?string $q,
        ?array $empIds = null,
    ): Builder {
        $contractual = EmploymentStatus::designationIds('contractual_designation_ids');
        $minMonths = (int) config('leave.regularization_entitlement.min_months', 6);
        $maxMonths = (int) config('leave.regularization_entitlement.max_months', 12);

        // ≥ minMonths: hired on or before asOf − minMonths
        // < maxMonths: hired after asOf − maxMonths
        $latestHireForMin = $asOf->subMonthsNoOverflow($minMonths)->toDateString();
        $earliestHireExclusive = $asOf->subMonthsNoOverflow($maxMonths)->toDateString();
        $yearStart = $this->yearStart($year)->toDateString();

        $query = Employee::query()
            ->where('emp_status', 1)
            ->whereNotNull('date_hired')
            ->whereDate('date_hired', '>', $yearStart)
            ->whereDate('date_hired', '<=', $latestHireForMin)
            ->whereDate('date_hired', '>', $earliestHireExclusive);

        if ($contractual !== []) {
            $query->whereNotIn('designation', $contractual);
        }

        $annualRecipients = $this->annualEntitlementEmpIds($year);

        if ($annualRecipients !== []) {
            $query->whereNotIn('id', $annualRecipients);
        }

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
     * @return list<int>
     */
    private function annualEntitlementEmpIds(int $year): array
    {
        [$start, $end] = $this->yearWindowUtc($year);

        return LeaveBalanceTransaction::query()
            ->where('reason', BalanceTransactionReason::AnnualEntitlement)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->distinct()
            ->pluck('emp_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
     * @param  array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}  $types
     * @return list<int>
     */
    private function fullyGrantedEmpIds(array $empIds, int $year, array $types): array
    {
        if ($empIds === []) {
            return [];
        }

        $byEmp = $this->grantedTypesByEmp($empIds, $year, $types);
        $fullyGranted = [];

        foreach ($byEmp as $empId => $done) {
            if ($done['vl'] && $done['sl'] && $done['ptb'] && $done['el']) {
                $fullyGranted[] = (int) $empId;
            }
        }

        return $fullyGranted;
    }

    /**
     * @param  list<int>  $empIds
     * @param  array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}  $types
     * @return array<int, array{vl: bool, sl: bool, ptb: bool, el: bool}>
     */
    private function grantedTypesByEmp(array $empIds, int $year, array $types): array
    {
        if ($empIds === []) {
            return [];
        }

        [$start, $end] = $this->yearWindowUtc($year);
        $typeIds = [
            'vl' => (int) $types['vl']->id,
            'sl' => (int) $types['sl']->id,
            'ptb' => (int) $types['ptb']->id,
            'el' => (int) $types['el']->id,
        ];

        $rows = LeaveBalanceTransaction::query()
            ->where('reason', BalanceTransactionReason::RegularizationEntitlement)
            ->whereIn('emp_id', $empIds)
            ->whereIn('leave_type_id', array_values($typeIds))
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get(['emp_id', 'leave_type_id']);

        $byEmp = [];

        foreach ($empIds as $empId) {
            $byEmp[$empId] = ['vl' => false, 'sl' => false, 'ptb' => false, 'el' => false];
        }

        $idToKey = array_flip($typeIds);

        foreach ($rows as $row) {
            $empId = (int) $row->emp_id;
            $key = $idToKey[(int) $row->leave_type_id] ?? null;

            if ($key !== null) {
                $byEmp[$empId][$key] = true;
            }
        }

        return $byEmp;
    }

    /**
     * @param  array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}  $types
     * @return array{vl: bool, sl: bool, ptb: bool, el: bool}
     */
    private function grantedTypesForEmp(int $empId, int $year, array $types): array
    {
        $map = $this->grantedTypesByEmp([$empId], $year, $types);

        return $map[$empId] ?? ['vl' => false, 'sl' => false, 'ptb' => false, 'el' => false];
    }

    /**
     * @param  list<int>  $empIds
     * @param  array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}  $types
     * @return Collection<int, Employee>
     *
     * @throws ValidationException
     */
    private function loadAndValidateTargets(
        array $empIds,
        CarbonImmutable $asOf,
        int $year,
        array $types,
    ): Collection {
        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $empIds)
            ->get()
            ->keyBy('id');

        $missing = [];
        $ineligible = [];
        $annualRecipients = array_fill_keys($this->annualEntitlementEmpIds($year), true);

        foreach ($empIds as $empId) {
            $employee = $employees->get($empId);

            if ($employee === null) {
                $missing[] = $empId;

                continue;
            }

            if (! $this->isEligible($employee, $asOf, $year, $annualRecipients)) {
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
                    'The selected employees are not eligible for newly regularized grant in '.$year
                    .' (inactive, contractual, missing hire date, outside 6–12 month tenure,'
                    .' hired on/before 1 January, or already received annual entitlement): '
                    .implode(', ', $ineligible).'.',
                ],
            ]);
        }

        $fullyGranted = $this->fullyGrantedEmpIds($empIds, $year, $types);

        if ($fullyGranted !== []) {
            throw ValidationException::withMessages([
                'emp_ids' => [
                    'Regularization entitlement for '.$year.' was already granted to: '
                    .implode(', ', $fullyGranted).'.',
                ],
            ]);
        }

        return $employees->sortBy('id')->values();
    }

    /**
     * @param  array<int, true>  $annualRecipients
     */
    private function isEligible(
        Employee $employee,
        CarbonImmutable $asOf,
        int $year,
        array $annualRecipients = [],
    ): bool {
        if (! $employee->isActive() || $employee->date_hired === null) {
            return false;
        }

        if (EmploymentStatus::fromDesignationId((int) $employee->designation) === EmploymentStatus::Contractual) {
            return false;
        }

        $hired = CarbonImmutable::parse($employee->date_hired->toDateString(), $this->timezone())->startOfDay();
        $yearStart = $this->yearStart($year);

        if (! $hired->gt($yearStart)) {
            return false;
        }

        $minMonths = (int) config('leave.regularization_entitlement.min_months', 6);
        $maxMonths = (int) config('leave.regularization_entitlement.max_months', 12);

        $sixMonthsAgo = $asOf->subMonthsNoOverflow($minMonths);
        $twelveMonthsAgo = $asOf->subMonthsNoOverflow($maxMonths);

        if ($hired->gt($sixMonthsAgo)) {
            return false;
        }

        if (! $hired->gt($twelveMonthsAgo)) {
            return false;
        }

        return ! isset($annualRecipients[(int) $employee->id]);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array{vl: LeaveType, sl: LeaveType, ptb: LeaveType, el: LeaveType}  $types
     * @param  list<int>  $fullyGrantedEmpIds
     */
    private function attachEntitlements(
        Collection $employees,
        CarbonImmutable $asOf,
        int $year,
        array $types,
        array $fullyGrantedEmpIds,
    ): void {
        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        $grantedSet = array_fill_keys($fullyGrantedEmpIds, true);
        $typeIds = [
            'vl' => (int) $types['vl']->id,
            'sl' => (int) $types['sl']->id,
            'ptb' => (int) $types['ptb']->id,
            'el' => (int) $types['el']->id,
        ];

        $balancesByEmp = LeaveBalance::query()
            ->whereIn('emp_id', $employees->pluck('id')->all())
            ->whereIn('leave_type_id', array_values($typeIds))
            ->get()
            ->groupBy(fn (LeaveBalance $row): int => (int) $row->emp_id);

        foreach ($employees as $employee) {
            $grants = $this->computeGrants($employee, $asOf, $year);
            $alreadyGranted = isset($grantedSet[(int) $employee->id]);

            $byType = ($balancesByEmp->get((int) $employee->id) ?? collect())
                ->keyBy('leave_type_id');

            $vlAvailable = $this->decimalString($byType->get($typeIds['vl'])?->available);
            $slAvailable = $this->decimalString($byType->get($typeIds['sl'])?->available);
            $ptbAvailable = $this->decimalString($byType->get($typeIds['ptb'])?->available);
            $elAvailable = $this->decimalString($byType->get($typeIds['el'])?->available);

            $employee->setAttribute('regularization_date', $grants['regularization_date']);
            $employee->setAttribute('months_of_service', $grants['months_of_service']);
            $employee->setAttribute('months_prorated', $grants['months_prorated']);
            $employee->setAttribute('vl_available', $vlAvailable);
            $employee->setAttribute('sl_available', $slAvailable);
            $employee->setAttribute('ptb_available', $ptbAvailable);
            $employee->setAttribute('el_available', $elAvailable);
            $employee->setAttribute('vl_grant', $grants['vl_grant']);
            $employee->setAttribute('sl_grant', $grants['sl_grant']);
            $employee->setAttribute('ptb_grant', $grants['ptb_grant']);
            $employee->setAttribute('el_grant', $grants['el_grant']);
            $employee->setAttribute(
                'vl_after',
                $alreadyGranted ? $vlAvailable : bcadd($vlAvailable, $grants['vl_grant'], 2),
            );
            $employee->setAttribute(
                'sl_after',
                $alreadyGranted ? $slAvailable : bcadd($slAvailable, $grants['sl_grant'], 2),
            );
            $employee->setAttribute(
                'ptb_after',
                $alreadyGranted ? $ptbAvailable : bcadd($ptbAvailable, $grants['ptb_grant'], 2),
            );
            $employee->setAttribute(
                'el_after',
                $alreadyGranted ? $elAvailable : bcadd($elAvailable, $grants['el_grant'], 2),
            );
            $employee->setAttribute('already_granted', $alreadyGranted);
        }
    }

    /**
     * @return array{
     *     regularization_date: string,
     *     months_of_service: string,
     *     months_prorated: int,
     *     vl_grant: string,
     *     sl_grant: string,
     *     ptb_grant: string,
     *     el_grant: string
     * }
     */
    private function computeGrants(Employee $employee, CarbonImmutable $asOf, int $year): array
    {
        $hired = CarbonImmutable::parse($employee->date_hired->toDateString(), $this->timezone())->startOfDay();
        $regularization = $hired->addMonthsNoOverflow(6);
        $monthsProrated = $this->proratedMonths($regularization, $year);
        $rate = (string) config('leave.regularization_entitlement.monthly_rate', '1.25');
        $vlSlGrant = bcmul((string) $monthsProrated, $rate, 2);

        return [
            'regularization_date' => $regularization->toDateString(),
            'months_of_service' => $this->formatServiceDuration($hired, $asOf),
            'months_prorated' => $monthsProrated,
            'vl_grant' => $vlSlGrant,
            'sl_grant' => $vlSlGrant,
            'ptb_grant' => bcadd((string) config('leave.regularization_entitlement.ptb', '180.00'), '0', 2),
            'el_grant' => bcadd((string) config('leave.regularization_entitlement.el', '5.00'), '0', 2),
        ];
    }

    /**
     * Inclusive calendar months from the regularization month through December of $year.
     */
    private function proratedMonths(CarbonImmutable $regularization, int $year): int
    {
        if ($regularization->year > $year) {
            return 0;
        }

        if ($regularization->year < $year) {
            return 12;
        }

        return max(0, 12 - $regularization->month + 1);
    }

    private function formatServiceDuration(CarbonImmutable $hired, CarbonImmutable $asOf): string
    {
        if ($hired->gt($asOf)) {
            return '0 months, 0 days';
        }

        $interval = $hired->diff($asOf);
        $totalMonths = ($interval->y * 12) + $interval->m;
        $days = $interval->d;

        $monthLabel = $totalMonths === 1 ? '1 month' : $totalMonths.' months';
        $dayLabel = $days === 1 ? '1 day' : $days.' days';

        return $monthLabel.', '.$dayLabel;
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
