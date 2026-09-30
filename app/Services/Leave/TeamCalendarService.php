<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Approved team leaves for the Team Leave Calendar.
 *
 * PIC: employees in the viewer's formspic groups (+ the viewer).
 * President: P4 employees only (formspic.fldRole = 4); group_id is ignored.
 *
 * Statuses: confirmed, pending_conversion, converted_to_sl, converted_to_el.
 * pending_approval / rejected / cancelled are omitted.
 */
class TeamCalendarService
{
    /** @var list<LeaveStatus> */
    public const STATUSES = [
        LeaveStatus::Confirmed,
        LeaveStatus::PendingConversion,
        LeaveStatus::ConvertedToSl,
        LeaveStatus::ConvertedToEl,
    ];

    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveDurationService $durationService,
        private readonly SpecialLeavePolicy $specialLeavePolicy,
    ) {}

    /**
     * @return Collection<int, LeaveInfo>
     *
     * @throws AuthorizationException
     */
    public function forApprover(
        Employee $approver,
        string $from,
        string $to,
        ?string $code,
        ?int $groupId,
    ): Collection {
        $empIds = $this->employeeIdsForCalendar($approver, $groupId);

        if ($empIds === []) {
            return collect();
        }

        $query = LeaveInfo::query()
            ->with(['leaveType'])
            ->whereIn('emp_id', $empIds)
            ->whereIn('status', self::STATUSES)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from);

        if ($code !== null && $code !== '') {
            $query->whereHas('leaveType', function ($q) use ($code): void {
                $q->where('code', $code);
            });
        }

        $leaves = $query
            ->orderBy('start_date')
            ->orderBy('emp_id')
            ->orderBy('id')
            ->get();

        $this->attachEmployees($leaves);
        $this->attachCalendarDates($leaves, $from, $to);

        return $leaves
            ->filter(function (LeaveInfo $leave): bool {
                $dates = $leave->getAttribute('calendar_dates');

                return is_array($dates) && $dates !== [];
            })
            ->values();
    }

    /**
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    private function employeeIdsForCalendar(Employee $approver, ?int $groupId): array
    {
        $context = $this->approverResolver->contextFor((int) $approver->id);
        $approverId = (int) $approver->id;

        if ($context->isPresident) {
            return $this->approverResolver->p4EmployeeIds();
        }

        if ($groupId !== null) {
            $group = Group::query()->find($groupId);

            if ($group === null || ! in_array($group->abbreviation, $context->groupCodes, true)) {
                throw new AuthorizationException(
                    'You are not allowed to view the calendar for this group.',
                );
            }

            return $this->approverResolver->employeeIdsInEffectiveGroups([$groupId]);
        }

        $empIds = $this->approverResolver->employeeIdsInEffectiveGroups(
            $this->groupIdsForCodes($context->groupCodes),
        );
        $empIds[] = $approverId;

        return array_values(array_unique($empIds));
    }

    /**
     * @param  list<string>  $groupCodes
     * @return list<int>
     */
    private function groupIdsForCodes(array $groupCodes): array
    {
        if ($groupCodes === []) {
            return [];
        }

        return Group::query()
            ->whereIn('abbreviation', $groupCodes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
            ->with('group')
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

    /**
     * @param  Collection<int, LeaveInfo>  $leaves
     */
    private function attachCalendarDates(Collection $leaves, string $from, string $to): void
    {
        if ($leaves->isEmpty()) {
            return;
        }

        $holidayDates = $this->durationService->holidayDatesBetween($from, $to);

        foreach ($leaves as $leave) {
            $code = strtoupper((string) ($leave->leaveType?->code ?? ''));
            $calendarDays = $code === 'SPL'
                ? $this->specialLeavePolicy->usesCalendarDaysForLeave($leave)
                : LeaveTypeRules::usesCalendarDays($code);

            if ($calendarDays) {
                $leave->setAttribute('calendar_dates', $this->durationService->calendarDatesBetween(
                    $leave->start_date->toDateString(),
                    $leave->end_date->toDateString(),
                    $from,
                    $to,
                ));

                continue;
            }

            $leave->setAttribute('calendar_dates', $this->durationService->workdaysBetween(
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
                $from,
                $to,
                $holidayDates,
            ));
        }
    }
}
