<?php

namespace App\Http\Resources;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveEligibleApproverService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class LeaveResource extends JsonResource
{
    /**
     * @var array<int, string|null>
     */
    private static array $approverNameCache = [];

    /**
     * @var array<int, Employee|null>
     */
    private static array $employeeOnLeaveCache = [];

    /**
     * @var array<int, Employee|null>
     */
    private static array $filedByCache = [];

    /**
     * @param  iterable<int, LeaveInfo>  $leaves
     */
    public static function hydrateApproverNames(iterable $leaves): void
    {
        $ids = collect($leaves)
            ->flatMap(function (LeaveInfo $leave) {
                if (! $leave->relationLoaded('approvalRequirements')) {
                    return [];
                }

                return $leave->approvalRequirements->map(function ($requirement) {
                    if (! $requirement->relationLoaded('history') || $requirement->history === null) {
                        return null;
                    }

                    return (int) $requirement->history->approver_emp_id;
                });
            })
            ->filter()
            ->unique()
            ->values()
            ->reject(fn (int $id) => array_key_exists($id, self::$approverNameCache));

        if ($ids->isNotEmpty()) {
            Employee::query()
                ->whereIn('id', $ids->all())
                ->get()
                ->each(function (Employee $employee) {
                    self::$approverNameCache[(int) $employee->id] = $employee->fullName();
                });

            foreach ($ids as $id) {
                if (! array_key_exists($id, self::$approverNameCache)) {
                    self::$approverNameCache[$id] = null;
                }
            }
        }

        foreach ($leaves as $leave) {
            if (! $leave->relationLoaded('approvalRequirements')) {
                continue;
            }

            foreach ($leave->approvalRequirements as $requirement) {
                if (! $requirement->relationLoaded('history') || $requirement->history === null) {
                    continue;
                }

                $approverId = (int) $requirement->history->approver_emp_id;
                $requirement->history->setAttribute(
                    'approver_name',
                    self::$approverNameCache[$approverId] ?? null,
                );
            }
        }
    }

    /**
     * Attach person-on-leave (employee_list + group) for LeaveResource `employee` nest.
     *
     * @param  iterable<int, LeaveInfo>  $leaves
     */
    public static function hydrateEmployeesOnLeave(iterable $leaves): void
    {
        $leaves = collect($leaves)->filter(fn ($item) => $item instanceof LeaveInfo);

        $missingIds = $leaves
            ->reject(fn (LeaveInfo $leave) => $leave->getAttribute('employee_on_leave') instanceof Employee)
            ->map(fn (LeaveInfo $leave) => (int) $leave->emp_id)
            ->unique()
            ->values()
            ->reject(fn (int $id) => array_key_exists($id, self::$employeeOnLeaveCache));

        if ($missingIds->isNotEmpty()) {
            $resolver = app(LeaveApproverResolver::class);

            Employee::query()
                ->with('group.department')
                ->whereIn('id', $missingIds->all())
                ->get()
                ->each(function (Employee $employee) use ($resolver) {
                    $resolver->applyEffectiveGroup($employee);
                    self::$employeeOnLeaveCache[(int) $employee->id] = $employee;
                });

            foreach ($missingIds as $id) {
                if (! array_key_exists($id, self::$employeeOnLeaveCache)) {
                    self::$employeeOnLeaveCache[$id] = null;
                }
            }
        }

        foreach ($leaves as $leave) {
            if ($leave->getAttribute('employee_on_leave') instanceof Employee) {
                continue;
            }

            $empId = (int) $leave->emp_id;
            $leave->setAttribute(
                'employee_on_leave',
                self::$employeeOnLeaveCache[$empId] ?? null,
            );
        }
    }

    /**
     * Attach filer for LeaveResource `filed_by` nest.
     *
     * Reuses the person-on-leave cache when `filed_by_emp_id` equals `emp_id`.
     *
     * @param  iterable<int, LeaveInfo>  $leaves
     */
    public static function hydrateFiledBy(iterable $leaves): void
    {
        $leaves = collect($leaves)->filter(fn ($item) => $item instanceof LeaveInfo);

        $missingIds = $leaves
            ->reject(fn (LeaveInfo $leave) => $leave->getAttribute('filed_by_employee') instanceof Employee)
            ->map(fn (LeaveInfo $leave) => (int) $leave->filed_by_emp_id)
            ->unique()
            ->values()
            ->reject(function (int $id) {
                return array_key_exists($id, self::$employeeOnLeaveCache)
                    || array_key_exists($id, self::$filedByCache);
            });

        if ($missingIds->isNotEmpty()) {
            Employee::query()
                ->whereIn('id', $missingIds->all())
                ->get()
                ->each(function (Employee $employee) {
                    self::$filedByCache[(int) $employee->id] = $employee;
                });

            foreach ($missingIds as $id) {
                if (! array_key_exists($id, self::$filedByCache)) {
                    self::$filedByCache[$id] = null;
                }
            }
        }

        foreach ($leaves as $leave) {
            if ($leave->getAttribute('filed_by_employee') instanceof Employee) {
                continue;
            }

            $filerId = (int) $leave->filed_by_emp_id;
            $leave->setAttribute(
                'filed_by_employee',
                self::$employeeOnLeaveCache[$filerId]
                    ?? self::$filedByCache[$filerId]
                    ?? null,
            );
        }
    }

    public static function collection($resource): AnonymousResourceCollection
    {
        $items = $resource instanceof Collection
            ? $resource
            : collect(
                method_exists($resource, 'getCollection')
                    ? $resource->getCollection()
                    : $resource
            );

        $leaves = $items->filter(fn ($item) => $item instanceof LeaveInfo);
        static::hydrateApproverNames($leaves);
        static::hydrateEmployeesOnLeave($leaves);
        static::hydrateFiledBy($leaves);
        LeaveConversionResource::hydrateReviewersFromLeaves($leaves);

        return parent::collection($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof LeaveInfo) {
            static::hydrateApproverNames([$this->resource]);
            static::hydrateEmployeesOnLeave([$this->resource]);
            static::hydrateFiledBy([$this->resource]);
            LeaveConversionResource::hydrateReviewersFromLeaves([$this->resource]);
        }

        $employee = $this->employee_on_leave instanceof Employee
            ? $this->employee_on_leave
            : null;

        $filedBy = $this->filed_by_employee instanceof Employee
            ? $this->filed_by_employee
            : null;

        return [
            'id' => $this->id,
            'emp_id' => $this->emp_id,
            'filed_by_emp_id' => $this->filed_by_emp_id,
            'status' => $this->status->value,
            'leave_type' => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->type,
            ],
            'duration' => $this->duration,
            'paid_days' => $this->paid_days,
            'lwop_days' => $this->lwop_days,
            'day_type' => $this->day_type->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'leave_reason' => $this->leave_reason,
            'has_attachment' => filled($this->leave_attachment),
            'attachment_url' => filled($this->leave_attachment)
                ? url("/api/leaves/{$this->id}/attachment")
                : null,
            'cancellation_reason' => $this->cancellation_reason,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'employee' => EmployeeNest::from($employee),
            'filed_by' => EmployeeNest::summary($filedBy),
            'approval_requirements' => $this->whenLoaded('approvalRequirements', function () {
                return $this->approvalRequirements->map(fn ($requirement) => [
                    'display_order' => $requirement->display_order,
                    'allowed_levels' => $requirement->allowed_levels,
                    'required_approver_type' => $requirement->required_approver_type?->value,
                    'is_satisfied' => $requirement->is_satisfied,
                    'satisfied_at' => $requirement->satisfied_at?->toIso8601String(),
                    'history' => $requirement->relationLoaded('history') && $requirement->history
                        ? [
                            'approver_emp_id' => $requirement->history->approver_emp_id,
                            'approver_name' => $requirement->history->approver_name
                                ?? self::$approverNameCache[(int) $requirement->history->approver_emp_id]
                                ?? null,
                            'approver_type' => $requirement->history->approver_type->value,
                            'is_approved' => $requirement->history->is_approved,
                            'remarks' => $requirement->history->remarks,
                            'created_at' => $requirement->history->created_at?->toIso8601String(),
                        ]
                        : null,
                ]);
            }),
            'eligible_approvers' => $this->resource instanceof LeaveInfo
                ? app(LeaveEligibleApproverService::class)->forLeave($this->resource)
                : [],
            'conversion' => $this->whenLoaded('conversion', function () {
                if ($this->conversion === null) {
                    return null;
                }

                $conversion = $this->conversion;

                return [
                    'id' => $conversion->id,
                    'status' => $conversion->status->value,
                    'from_leave_type' => $conversion->relationLoaded('fromLeaveType') && $conversion->fromLeaveType
                        ? [
                            'id' => $conversion->fromLeaveType->id,
                            'code' => $conversion->fromLeaveType->code,
                            'name' => $conversion->fromLeaveType->type,
                        ]
                        : null,
                    'to_leave_type' => $conversion->relationLoaded('toLeaveType') && $conversion->toLeaveType
                        ? [
                            'id' => $conversion->toLeaveType->id,
                            'code' => $conversion->toLeaveType->code,
                            'name' => $conversion->toLeaveType->type,
                        ]
                        : null,
                    'has_med_cert' => filled($conversion->med_cert_attachment),
                    'med_cert_url' => filled($conversion->med_cert_attachment)
                        ? url("/api/conversions/{$conversion->id}/med-cert")
                        : null,
                    'reviewed_by_emp_id' => $conversion->reviewed_by_emp_id,
                    'reviewed_by' => LeaveConversionResource::reviewedByFrom($conversion),
                    'remarks' => $conversion->remarks,
                    'reviewed_at' => $conversion->reviewed_at?->toIso8601String(),
                    'created_at' => $conversion->created_at?->toIso8601String(),
                ];
            }),
        ];
    }
}
