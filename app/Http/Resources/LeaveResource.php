<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'leave_attachment' => $this->leave_attachment,
            'cancellation_reason' => $this->cancellation_reason,
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
                            'approver_type' => $requirement->history->approver_type->value,
                            'is_approved' => $requirement->history->is_approved,
                            'remarks' => $requirement->history->remarks,
                            'created_at' => $requirement->history->created_at?->toIso8601String(),
                        ]
                        : null,
                ]);
            }),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
