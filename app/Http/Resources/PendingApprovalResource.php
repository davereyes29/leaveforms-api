<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim row for GET /api/approvals and GET /api/approvals/pending.
 *
 * Expects LeaveInfo with leaveType + approvalRequirements loaded,
 * and transient employee_on_leave (Employee|null) set by the list service.
 */
class PendingApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'leave_type' => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->type,
            ],
            'duration' => $this->duration,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'employee' => EmployeeNest::from($this->employee_on_leave),
            'approvals_completed' => $this->approvalRequirements
                ->where('is_satisfied', true)
                ->count(),
            'approvals_required' => $this->approvalRequirements->count(),
        ];
    }
}
