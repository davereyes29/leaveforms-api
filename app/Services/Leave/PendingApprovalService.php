<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lists leave requests awaiting action from the current approver.
 */
class PendingApprovalService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return Collection<int, LeaveInfo>
     */
    public function forApprover(Employee $approver, ?string $q = null): Collection
    {
        $context = $this->approverResolver->contextFor($approver->id);

        $empIds = $this->matchingEmpIds($q);

        if ($empIds !== null && $empIds === []) {
            return collect();
        }

        $query = LeaveInfo::query()
            ->with([
                'leaveType',
                'approvalRequirements',
            ])
            ->where('status', LeaveStatus::PendingApproval);

        if ($empIds !== null) {
            $query->whereIn('emp_id', $empIds);
        }

        $leaves = $query
            ->orderByDesc('created_at')
            ->get();

        $pending = $leaves->filter(function (LeaveInfo $leave) use ($context) {
            $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

            foreach ($leave->approvalRequirements as $requirement) {
                if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                    return true;
                }
            }

            return false;
        })->values();

        $this->attachEmployees($pending);

        return $pending;
    }

    /**
     * @return list<int>|null null = no employee-scope filter
     */
    private function matchingEmpIds(?string $q): ?array
    {
        if ($q === null || $q === '') {
            return null;
        }

        $query = Employee::query();
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
