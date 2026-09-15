<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Department;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Org-wide leave requests for LEAVE_ADMIN_EMP_ID.
 *
 * No PIC / formspic scoping. Includes the admin's own leaves.
 * Optional group_id filters by the person-on-leave's effective primary group.
 * Optional q searches the person on leave by name or employee number.
 * Default statuses: pending_approval, pending_conversion, confirmed, converted_to_sl, converted_to_el.
 * rejected and cancelled are omitted.
 */
class AdminLeaveListService
{
    /** @var list<LeaveStatus> */
    public const DEFAULT_STATUSES = [
        LeaveStatus::PendingApproval,
        LeaveStatus::PendingConversion,
        LeaveStatus::Confirmed,
        LeaveStatus::ConvertedToSl,
        LeaveStatus::ConvertedToEl,
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LeaveInfo>
     *
     * @throws AuthorizationException
     */
    public function paginateForAdmin(
        Employee $admin,
        ?LeaveStatus $status,
        ?string $code,
        int $perPage,
        string $sort,
        ?int $groupId = null,
        ?string $q = null,
    ): LengthAwarePaginator {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view all leave requests.');
        }

        $empIds = $this->matchingEmpIds($groupId, $q);

        if ($empIds !== null && $empIds === []) {
            return LeaveInfo::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        $statuses = $status !== null ? [$status] : self::DEFAULT_STATUSES;

        $query = LeaveInfo::query()
            ->with([
                'leaveType',
                'approvalRequirements',
            ])
            ->whereIn('status', $statuses);

        if ($empIds !== null) {
            $query->whereIn('emp_id', $empIds);
        }

        if ($code !== null && $code !== '') {
            $query->whereHas('leaveType', function (Builder $type) use ($code): void {
                $type->where('code', $code);
            });
        }

        $direction = $sort === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy('created_at', $direction)
            ->paginate($perPage);

        $this->attachEmployees(collect($paginator->items()));

        return $paginator;
    }

    /**
     * Org-wide group_list rows for the All Leave Requests group filter.
     *
     * @return Collection<int, Group>
     *
     * @throws AuthorizationException
     */
    public function groupsForAdmin(Employee $admin): Collection
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view all leave requests.');
        }

        return Group::query()
            ->orderBy('abbreviation')
            ->orderBy('name')
            ->get();
    }

    /**
     * Org-wide department_list rows for admin group / balance filters.
     *
     * @return Collection<int, Department>
     *
     * @throws AuthorizationException
     */
    public function departmentsForAdmin(Employee $admin): Collection
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view all leave requests.');
        }

        return Department::query()
            ->orderBy('abbreviation')
            ->orderBy('name')
            ->get();
    }

    /**
     * Slim org-wide employee list for admin report / filter pickers.
     * Active employees only. Optional group_id uses the effective primary group.
     *
     * @return Collection<int, Employee>
     *
     * @throws AuthorizationException
     */
    public function employeesForAdmin(Employee $admin, ?int $groupId = null): Collection
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view the employee list.');
        }

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

        $employees = $query
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
     * @return list<int>|null null = no employee-scope filter
     */
    private function matchingEmpIds(?int $groupId, ?string $q): ?array
    {
        $term = trim((string) $q);
        $hasSearch = $term !== '';

        if ($groupId === null && ! $hasSearch) {
            return null;
        }

        if ($groupId !== null && ! $hasSearch) {
            return $this->approverResolver->employeeIdsInEffectiveGroups([$groupId]);
        }

        $query = Employee::query();

        if ($groupId !== null) {
            $ids = $this->approverResolver->employeeIdsInEffectiveGroups([$groupId]);

            if ($ids === []) {
                return [];
            }

            $query->whereIn('id', $ids);
        }

        $this->applySearch($query, $q);

        return $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
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
     * @param  Collection<int, LeaveInfo>  $leaves
     */
    private function attachEmployees(Collection $leaves): void
    {
        if ($leaves->isEmpty()) {
            return;
        }

        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $leaves->pluck('emp_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        foreach ($leaves as $leave) {
            $leave->setAttribute('employee_on_leave', $employees->get($leave->emp_id));
        }
    }
}
