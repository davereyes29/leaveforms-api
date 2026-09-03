<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Activity Log row for GET /api/activities.
 *
 * Expects LeaveActivityHistory enriched by LeaveActivityListService
 * (performed_by_name, balance_impact, ledger).
 */
class LeaveActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $leave = $this->leaveInfo;
        $leaveType = $leave?->leaveType;

        return [
            'id' => $this->id,
            'leave_id' => $this->leave_id,
            'timestamp' => $this->created_at?->toIso8601String(),
            'activity_type' => $this->activity_type->value,
            'activity' => $this->activity_type->label($this->conversionTargetCode()),
            'leave_type' => $leaveType === null ? null : [
                'id' => $leaveType->id,
                'code' => $leaveType->code,
                'name' => $leaveType->type,
            ],
            'balance_impact' => $this->balance_impact ?? 'No change',
            'ledger' => collect($this->ledger ?? [])->map(fn ($tx) => [
                'id' => $tx->id,
                'reason' => $tx->reason->value,
                'leave_type_code' => $tx->leaveType?->code,
                'available_delta' => $tx->available_delta,
                'reserved_delta' => $tx->reserved_delta,
            ])->values()->all(),
            'performed_by' => [
                'id' => $this->performed_by_emp_id,
                'name' => $this->performed_by_name,
            ],
            'remarks' => $this->remarks,
        ];
    }

    private function conversionTargetCode(): ?string
    {
        if (! $this->activity_type?->isConversion()) {
            return null;
        }

        return $this->leaveInfo?->conversion?->toLeaveType?->code;
    }
}
