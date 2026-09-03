<?php

namespace App\Http\Resources;

use App\Enums\Leave\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Administrative Log row for GET /api/admin/activities.
 *
 * Expects LeaveActivityHistory enriched by AdminActivityListService
 * (performed_by_name, employee_on_leave, subject_employee).
 */
class AdminLeaveActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_id' => $this->leave_id,
            'timestamp' => $this->created_at?->toIso8601String(),
            'activity_type' => $this->activity_type->value,
            'activity' => $this->activity_type->label(
                $this->activity_type instanceof ActivityType && $this->activity_type->isConversion()
                    ? $this->leaveInfo?->conversion?->toLeaveType?->code
                    : null,
            ),
            'leave_type' => $this->leaveTypePayload(),
            'employee' => $this->employeePayload(),
            'performed_by' => [
                'id' => $this->performed_by_emp_id,
                'name' => $this->performed_by_name,
            ],
            'remarks' => $this->remarks,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function leaveTypePayload(): ?array
    {
        if ($this->activity_type instanceof ActivityType && $this->activity_type->isConversion()) {
            $conversion = $this->leaveInfo?->conversion;
            $from = $conversion?->fromLeaveType;
            $to = $conversion?->toLeaveType;

            if ($from !== null && $to !== null) {
                return [
                    'id' => $from->id,
                    'code' => $from->code,
                    'name' => $from->type.' to '.$to->type,
                ];
            }
        }

        $leaveType = $this->leaveType ?? $this->leaveInfo?->leaveType;

        if ($leaveType === null) {
            return null;
        }

        return [
            'id' => $leaveType->id,
            'code' => $leaveType->code,
            'name' => $leaveType->type,
        ];
    }

    /**
     * @return array{id: ?int, employee_number: ?string, name: string, group: ?array{id: int, abbreviation: string|null, name: string|null}}|null
     */
    private function employeePayload(): ?array
    {
        if ($this->isBatchCredit()) {
            return [
                'id' => null,
                'employee_number' => null,
                'name' => 'Multiple Employees',
                'group' => null,
            ];
        }

        $employee = $this->employee_on_leave ?? $this->subject_employee;

        if ($employee === null) {
            return null;
        }

        $group = $employee->group;

        return [
            'id' => $employee->id,
            'employee_number' => (string) $employee->id,
            'name' => $employee->fullName(),
            'group' => $group === null ? null : [
                'id' => $group->id,
                'abbreviation' => $group->abbreviation,
                'name' => $group->name,
            ],
        ];
    }
}
