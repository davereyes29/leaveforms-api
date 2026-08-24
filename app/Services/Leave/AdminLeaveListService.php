<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Org-wide leave requests for LEAVE_ADMIN_EMP_ID.
 *
 * No PIC / formspic scoping. Includes the admin's own leaves.
 * Optional group_id filters by the person-on-leave's effective primary group.
 * Default statuses: pending_approval, pending_conversion, confirmed, converted_to_sl.
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
    ): LengthAwarePaginator {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can view all leave requests.');
        }

        $statuses = $status !== null ? [$status] : self::DEFAULT_STATUSES;

        $query = LeaveInfo::query()
            ->with([
                'leaveType',
                'approvalRequirements',
            ])
            ->whereIn('status', $statuses);

        if ($groupId !== null) {
            $empIds = $this->approverResolver->employeeIdsInEffectiveGroups([$groupId]);

            if ($empIds === []) {
                return LeaveInfo::query()->whereRaw('0 = 1')->paginate($perPage);
            }

            $query->whereIn('emp_id', $empIds);
        }

        if ($code !== null && $code !== '') {
            $query->whereHas('leaveType', function ($q) use ($code): void {
                $q->where('code', $code);
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
