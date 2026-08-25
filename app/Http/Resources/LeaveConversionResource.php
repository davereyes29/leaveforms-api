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
    /**
     * @var array<int, Employee|null>
     */
    private static array $reviewedByCache = [];

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
        self::hydrateReviewers($items);

        return parent::collection($resource);
    }

    /**
     * Attach conversion reviewer for `reviewed_by` nest.
     *
     * @param  iterable<int, mixed>  $items
     */
    public static function hydrateReviewers(iterable $items): void
    {
        $conversions = collect($items)->filter(fn ($item) => $item instanceof LeaveConversion);

        $missingIds = $conversions
            ->reject(fn (LeaveConversion $conversion) => $conversion->getAttribute('reviewed_by_employee') instanceof Employee)
            ->map(fn (LeaveConversion $conversion) => $conversion->reviewed_by_emp_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->reject(fn (int $id) => array_key_exists($id, self::$reviewedByCache));

        if ($missingIds->isNotEmpty()) {
            Employee::query()
                ->whereIn('id', $missingIds->all())
                ->get()
                ->each(function (Employee $employee) {
                    self::$reviewedByCache[(int) $employee->id] = $employee;
                });

            foreach ($missingIds as $id) {
                if (! array_key_exists($id, self::$reviewedByCache)) {
                    self::$reviewedByCache[$id] = null;
                }
            }
        }

        foreach ($conversions as $conversion) {
            if ($conversion->getAttribute('reviewed_by_employee') instanceof Employee) {
                continue;
            }

            if ($conversion->reviewed_by_emp_id === null) {
                $conversion->setAttribute('reviewed_by_employee', null);

                continue;
            }

            $reviewerId = (int) $conversion->reviewed_by_emp_id;
            $conversion->setAttribute(
                'reviewed_by_employee',
                self::$reviewedByCache[$reviewerId] ?? null,
            );
        }
    }

    /**
     * @param  iterable<int, mixed>  $leaves
     */
    public static function hydrateReviewersFromLeaves(iterable $leaves): void
    {
        $conversions = collect($leaves)
            ->filter(fn ($item) => $item instanceof LeaveInfo)
            ->filter(fn (LeaveInfo $leave) => $leave->relationLoaded('conversion') && $leave->conversion instanceof LeaveConversion)
            ->map(fn (LeaveInfo $leave) => $leave->conversion);

        self::hydrateReviewers($conversions);
    }

    /**
     * Slim nest (id / employee_number / name) for the admin who reviewed.
     *
     * @return array{id: int, employee_number: string, name: string}|null
     */
    public static function reviewedByFrom(LeaveConversion $conversion): ?array
    {
        $employee = $conversion->getAttribute('reviewed_by_employee') instanceof Employee
            ? $conversion->getAttribute('reviewed_by_employee')
            : null;

        return EmployeeNest::summary($employee);
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
            self::hydrateReviewers([$this->resource]);
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
            'reviewed_by' => $this->resource instanceof LeaveConversion
                ? self::reviewedByFrom($this->resource)
                : null,
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
