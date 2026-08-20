<?php

namespace App\Http\Resources;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class LeaveConversionResource extends JsonResource
{
    public static function collection($resource): AnonymousResourceCollection
    {
        $items = $resource instanceof Collection
            ? $resource
            : collect(
                method_exists($resource, 'getCollection')
                    ? $resource->getCollection()
                    : $resource
            );

        self::hydrateEmployeesOnLeave($items);

        return parent::collection($resource);
    }

    /**
     * @param  iterable<int, mixed>  $items
     */
    private static function hydrateEmployeesOnLeave(iterable $items): void
    {
        $leaves = collect($items)
            ->filter(fn ($item) => $item instanceof LeaveConversion && $item->relationLoaded('leaveInfo'))
            ->map(fn (LeaveConversion $conversion) => $conversion->leaveInfo)
            ->filter(fn ($leave) => $leave instanceof LeaveInfo);

        LeaveResource::hydrateEmployeesOnLeave($leaves);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof LeaveConversion) {
            self::hydrateEmployeesOnLeave([$this->resource]);
        }

        return [
            'id' => $this->id,
            'leave_id' => $this->leave_id,
            'status' => $this->status->value,
            'from_leave_type' => $this->whenLoaded('fromLeaveType', fn () => [
                'id' => $this->fromLeaveType->id,
                'code' => $this->fromLeaveType->code,
                'name' => $this->fromLeaveType->type,
            ]),
            'to_leave_type' => $this->whenLoaded('toLeaveType', fn () => [
                'id' => $this->toLeaveType->id,
                'code' => $this->toLeaveType->code,
                'name' => $this->toLeaveType->type,
            ]),
            'has_med_cert' => filled($this->med_cert_attachment),
            'med_cert_url' => filled($this->med_cert_attachment)
                ? url("/api/conversions/{$this->id}/med-cert")
                : null,
            'reviewed_by_emp_id' => $this->reviewed_by_emp_id,
            'remarks' => $this->remarks,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'leave' => $this->whenLoaded('leaveInfo', function () {
                $leave = $this->leaveInfo;
                $employee = $leave->employee_on_leave instanceof Employee
                    ? $leave->employee_on_leave
                    : null;

                return [
                    'id' => $leave->id,
                    'emp_id' => $leave->emp_id,
                    'status' => $leave->status->value,
                    'duration' => $leave->duration,
                    'paid_days' => $leave->paid_days,
                    'start_date' => $leave->start_date->toDateString(),
                    'end_date' => $leave->end_date->toDateString(),
                    'leave_reason' => $leave->leave_reason,
                    'leave_type' => $leave->relationLoaded('leaveType') ? [
                        'id' => $leave->leaveType->id,
                        'code' => $leave->leaveType->code,
                        'name' => $leave->leaveType->type,
                    ] : null,
                    'employee' => EmployeeNest::from($employee),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
