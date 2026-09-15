<?php

namespace App\Services\Leave;

use App\Enums\Leave\ApproverType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Formspic;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use Illuminate\Support\Collection;

/**
 * People responsible for the leave's current approval stage (display list).
 *
 * Permanent for the stage: still shown after someone acts. Who actually acted
 * lives on approval_requirements.history / conversion review fields.
 */
class LeaveEligibleApproverService
{
    private const PURPOSE_LEAVE_APPROVAL = 'Leave approval';

    private const PURPOSE_ADMIN_VALIDATION = 'Admin validation';

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    /**
     * @return list<array{emp_id: int, name: string, approver_type: string, purpose: string}>
     */
    public function forLeave(LeaveInfo $leave): array
    {
        // Settled migrated history only — live pending legacy leaves use normal PIC/admin pool.
        if (
            $leave->legacy_leave_id !== null
            && $leave->status !== LeaveStatus::PendingApproval
        ) {
            return $this->legacyHistoryEntries($leave);
        }

        if ($this->isConversionStage($leave->status)) {
            return $this->adminEntries();
        }

        return $this->leaveApprovalEntries($leave);
    }

    /**
     * Migrated leaves: show only the stub history actor — not today's full PIC pool.
     *
     * @return list<array{emp_id: int, name: string, approver_type: string, purpose: string}>
     */
    private function legacyHistoryEntries(LeaveInfo $leave): array
    {
        $leave->loadMissing('approvalRequirements.history');

        $entries = [];

        foreach ($leave->approvalRequirements->sortBy('display_order') as $requirement) {
            $history = $requirement->history;
            if ($history === null) {
                continue;
            }

            $empId = (int) $history->approver_emp_id;
            $employee = Employee::query()->find($empId);
            $approverType = $history->approver_type?->value ?? ApproverType::Leader->value;
            $purpose = $approverType === ApproverType::Admin->value
                ? self::PURPOSE_ADMIN_VALIDATION
                : self::PURPOSE_LEAVE_APPROVAL;

            $entries[] = $this->entry(
                $empId,
                $employee?->fullName() ?: "Employee #{$empId}",
                $approverType,
                $purpose,
            );
        }

        return $entries;
    }

    private function isConversionStage(LeaveStatus $status): bool
    {
        return $status === LeaveStatus::PendingConversion
            || $status->isConverted();
    }

    /**
     * @return list<array{emp_id: int, name: string, approver_type: string, purpose: string}>
     */
    private function adminEntries(): array
    {
        $admin = $this->adminEmployee();

        if ($admin === null) {
            return [];
        }

        return [
            $this->entry(
                (int) $admin->id,
                $admin->fullName(),
                ApproverType::Admin->value,
                self::PURPOSE_ADMIN_VALIDATION,
            ),
        ];
    }

    /**
     * Match snapshotted requirements (ignore is_satisfied) via formspic / president / admin.
     *
     * @return list<array{emp_id: int, name: string, approver_type: string, purpose: string}>
     */
    private function leaveApprovalEntries(LeaveInfo $leave): array
    {
        $leave->loadMissing('approvalRequirements');

        /** @var array<int, array{emp_id: int, name: string, approver_type: string, purpose: string}> $byEmpId */
        $byEmpId = [];

        foreach ($leave->approvalRequirements->sortBy('display_order') as $requirement) {
            foreach ($this->employeesForRequirement($leave, $requirement) as $row) {
                $byEmpId[$row['emp_id']] ??= $row;
            }
        }

        return array_values($byEmpId);
    }

    /**
     * @return Collection<int, array{emp_id: int, name: string, approver_type: string, purpose: string}>
     */
    private function employeesForRequirement(
        LeaveInfo $leave,
        LeaveApprovalRequirement $requirement,
    ): Collection {
        if ($requirement->required_approver_type === RequiredApproverType::President) {
            $president = $this->presidentEmployee();

            if ($president === null) {
                return collect();
            }

            return collect([
                $this->entry(
                    (int) $president->id,
                    $president->fullName(),
                    ApproverType::President->value,
                    self::PURPOSE_LEAVE_APPROVAL,
                ),
            ]);
        }

        if ($requirement->required_approver_type === RequiredApproverType::Admin) {
            $admin = $this->adminEmployee();

            if ($admin === null) {
                return collect();
            }

            return collect([
                $this->entry(
                    (int) $admin->id,
                    $admin->fullName(),
                    ApproverType::Admin->value,
                    self::PURPOSE_ADMIN_VALIDATION,
                ),
            ]);
        }

        $levels = $requirement->allowed_levels ?? [];

        if ($levels === []) {
            return collect();
        }

        $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

        if ($filerGroup === null) {
            return collect();
        }

        $matchingIds = Formspic::query()
            ->whereIn('fldRole', $levels)
            ->get()
            ->filter(fn (Formspic $row) => in_array($filerGroup, $row->groupsArray(), true))
            ->pluck('fldEmployeeNum')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($matchingIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->whereIn('id', $matchingIds->all())
            ->where('emp_status', 1)
            ->get()
            ->map(function (Employee $employee) {
                $type = $this->approverResolver
                    ->contextFor((int) $employee->id)
                    ->approverType();

                return $this->entry(
                    (int) $employee->id,
                    $employee->fullName(),
                    $type?->value ?? ApproverType::Leader->value,
                    self::PURPOSE_LEAVE_APPROVAL,
                );
            })
            ->values();
    }

    private function presidentEmployee(): ?Employee
    {
        $presidentId = (int) config('leave.president_emp_id');

        if ($presidentId <= 0) {
            return null;
        }

        return Employee::query()
            ->whereKey($presidentId)
            ->where('emp_status', 1)
            ->first();
    }

    private function adminEmployee(): ?Employee
    {
        $adminId = (int) config('leave.admin_emp_id');

        if ($adminId <= 0) {
            return null;
        }

        return Employee::query()
            ->whereKey($adminId)
            ->where('emp_status', 1)
            ->first();
    }

    /**
     * @return array{emp_id: int, name: string, approver_type: string, purpose: string}
     */
    private function entry(int $empId, string $name, string $approverType, string $purpose): array
    {
        return [
            'emp_id' => $empId,
            'name' => $name,
            'approver_type' => $approverType,
            'purpose' => $purpose,
        ];
    }
}
