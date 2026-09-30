<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * All leave requests for employees in the approver's formspic PIC groups,
 * or — for the president — all P4 employees (formspic.fldRole = 4).
 *
 * Unlike PendingApprovalService (actionable inbox), this is a team history view.
 * Optional q searches the person on leave by name or employee number (within PIC groups / P4s).
 * Optional group_id narrows to one PIC group (must be in the viewer's formspic fldGroups).
 * group_id is ignored for the president.
 * Default statuses: confirmed, rejected, pending_conversion, converted_to_sl, converted_to_el.
 * pending_approval stays on GET /approvals/pending; admin review on GET /conversions/pending.
 * The viewer's own leaves are excluded (use GET /leaves).
 */
class ApproverLeaveListService
{
    /** @var list<LeaveStatus> */
    public const DEFAULT_STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::Rejected,
        LeaveStatus::PendingConversion,
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
    public function paginateForApprover(
        Employee $approver,
        ?LeaveStatus $status,
        ?string $code,
        int $perPage,
        string $sort,
        ?int $groupId = null,
        ?string $q = null,
    ): LengthAwarePaginator {
        $context = $this->approverResolver->contextFor($approver->id);
        $empIds = $context->isPresident
            ? $this->approverResolver->p4EmployeeIds((int) $approver->id)
            : $this->employeeIdsInPicGroups($context->groupCodes, (int) $approver->id, $groupId);
        $empIds = $this->filterEmpIdsBySearch($empIds, $q);

        if ($empIds === []) {
            return LeaveInfo::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        $statuses = $status !== null ? [$status] : self::DEFAULT_STATUSES;

        $query = LeaveInfo::query()
            ->with([
                'leaveType',
                'approvalRequirements',
            ])
            ->whereIn('emp_id', $empIds)
            ->whereIn('status', $statuses);

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
     * @param  list<string>  $groupCodes
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    private function employeeIdsInPicGroups(array $groupCodes, int $excludeEmpId, ?int $groupId = null): array
    {
        if ($groupId !== null) {
            $group = Group::query()->find($groupId);

            if ($group === null || ! in_array($group->abbreviation, $groupCodes, true)) {
                throw new AuthorizationException(
                    'You are not allowed to view leave requests for this group.',
                );
            }

            return $this->approverResolver->employeeIdsInEffectiveGroups([$groupId], $excludeEmpId);
        }

        if ($groupCodes === []) {
            return [];
        }

        $groupIds = Group::query()
            ->whereIn('abbreviation', $groupCodes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($groupIds === []) {
            return [];
        }

        return $this->approverResolver->employeeIdsInEffectiveGroups($groupIds, $excludeEmpId);
    }

    /**
     * @param  list<int>  $empIds
     * @return list<int>
     */
    private function filterEmpIdsBySearch(array $empIds, ?string $q): array
    {
        $term = trim((string) $q);

        if ($empIds === [] || $term === '') {
            return $empIds;
        }

        $query = Employee::query()->whereIn('id', $empIds);
        $this->applySearch($query, $term);

        return $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
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
