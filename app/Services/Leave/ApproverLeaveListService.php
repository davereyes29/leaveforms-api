<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * All leave requests for employees in the approver's formspic PIC groups.
 *
 * Unlike PendingApprovalService (actionable inbox), this is a team history view.
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
     */
    public function paginateForApprover(
        Employee $approver,
        ?LeaveStatus $status,
        ?string $code,
        int $perPage,
        string $sort,
    ): LengthAwarePaginator {
        $context = $this->approverResolver->contextFor($approver->id);
        $empIds = $this->employeeIdsInPicGroups($context->groupCodes, (int) $approver->id);

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
     * @param  list<string>  $groupCodes
     * @return list<int>
     */
    private function employeeIdsInPicGroups(array $groupCodes, int $excludeEmpId): array
    {
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
