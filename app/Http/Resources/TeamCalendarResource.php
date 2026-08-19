<?php

namespace App\Http\Resources;

use App\Models\Kdtph\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Calendar bar for GET /api/team/calendar.
 *
 * Expects LeaveInfo with leaveType loaded, plus transient
 * employee_on_leave (Employee|null) and calendar_dates (list<string>).
 */
class TeamCalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'duration' => $this->duration,
            'day_type' => $this->day_type->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'dates' => $this->calendar_dates ?? [],
            'leave_type' => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->type,
            ],
            'employee' => $this->employeePayload($this->employee_on_leave),
        ];
    }

    /**
     * @return array{id: int, employee_number: string, name: string, group: ?array{id: int, abbreviation: string|null, name: string|null}}|null
     */
    private function employeePayload(?Employee $employee): ?array
    {
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
