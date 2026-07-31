<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveConversionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
