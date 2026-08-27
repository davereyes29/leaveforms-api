<?php

namespace App\Services\Leave;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Org-wide Administrative Log for LEAVE_ADMIN_EMP_ID.
 *
 * Leave-lifecycle rows plus summarized credit distributions and solo
 * manual adjustments. Group / name search match the person on leave,
 * the adjusted employee, or any recipient of a batch distribution.
 */
class AdminActivityListService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LeaveActivityHistory>
     *
     * @throws AuthorizationException
     */
    public function paginateForAdmin(
        Employee $admin,
        ?string $code = null,
        int $perPage = 15,
        string $sort = 'desc',
        ?int $groupId = null,
        ?string $q = null,
        ?string $month = null,
    ): LengthAwarePaginator {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view the administrative log.');
        }

        $empIds = $this->matchingEmpIds($groupId, $q);

        if ($empIds !== null && $empIds === []) {
            return LeaveActivityHistory::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        $direction = $sort === 'asc' ? 'asc' : 'desc';

        $query = LeaveActivityHistory::query()
            ->with([
                'leaveType',
                'leaveInfo.leaveType',
                'leaveInfo.conversion.fromLeaveType',
                'leaveInfo.conversion.toLeaveType',
            ]);

        $this->applyEmployeeScope($query, $empIds);
        $this->applyLeaveTypeCode($query, $code);
        $this->applyMonth($query, $month);

        $paginator = $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->enrichPage($paginator->getCollection());

        return $paginator;
    }

    /**
     * @param  list<int>|null  $empIds
     */
    private function applyEmployeeScope(Builder $query, ?array $empIds): void
    {
        if ($empIds === null) {
            return;
        }

        $query->where(function (Builder $inner) use ($empIds): void {
            $inner->whereHas('leaveInfo', fn (Builder $leave) => $leave->whereIn('emp_id', $empIds))
                ->orWhereIn('subject_emp_id', $empIds)
                ->orWhereHas('recipients', fn (Builder $recipients) => $recipients->whereIn('emp_id', $empIds));
        });
    }

    private function applyLeaveTypeCode(Builder $query, ?string $code): void
    {
        if ($code === null || $code === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($code): void {
            $inner->whereHas('leaveType', fn (Builder $type) => $type->where('code', $code))
                ->orWhereHas('leaveInfo.leaveType', fn (Builder $type) => $type->where('code', $code))
                ->orWhereHas('leaveInfo.conversion.fromLeaveType', fn (Builder $type) => $type->where('code', $code))
                ->orWhereHas('leaveInfo.conversion.toLeaveType', fn (Builder $type) => $type->where('code', $code));
        });
    }

    private function applyMonth(Builder $query, ?string $month): void
    {
        if ($month === null || $month === '') {
            return;
        }

        $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $tz)->startOfDay();
        $end = $start->addMonth();

        $query->where('created_at', '>=', $start->utc())
            ->where('created_at', '<', $end->utc());
    }

    /**
     * @return list<int>|null null = no employee-scope filter
     */
    private function matchingEmpIds(?int $groupId, ?string $q): ?array
    {
        if ($groupId === null && ($q === null || $q === '')) {
            return null;
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
     * @param  Collection<int, LeaveActivityHistory>  $activities
     */
    private function enrichPage(Collection $activities): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        $empIds = $activities
            ->flatMap(function (LeaveActivityHistory $activity): array {
                return array_filter([
                    $activity->leaveInfo?->emp_id,
                    $activity->subject_emp_id,
                    $activity->performed_by_emp_id,
                ]);
            })
            ->unique()
            ->values()
            ->all();

        $employees = $this->employeesById($empIds);

        foreach ($activities as $activity) {
            $onLeave = $employees->get($activity->leaveInfo?->emp_id);
            $subject = $employees->get($activity->subject_emp_id);

            $activity->setAttribute('employee_on_leave', $onLeave);
            $activity->setAttribute('subject_employee', $subject);
            $activity->setAttribute(
                'performed_by_name',
                $employees->get($activity->performed_by_emp_id)?->fullName(),
            );
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Employee>
     */
    private function employeesById(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        $employees = Employee::query()
            ->with('group.department')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($employees as $employee) {
            $this->approverResolver->applyEffectiveGroup($employee);
        }

        return $employees;
    }
}
