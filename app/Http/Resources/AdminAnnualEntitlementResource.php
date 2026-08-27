<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee row for GET/POST /api/admin/annual-entitlements.
 *
 * Expects Employee with group.department loaded, effective group applied,
 * and transient entitlement fields set by AdminAnnualEntitlementService.
 */
class AdminAnnualEntitlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee' => EmployeeNest::from($this->resource),
            'date_hired' => $this->date_hired?->toDateString(),
            'years_of_service' => $this->years_of_service,
            'vl_available' => $this->vl_available,
            'sl_available' => $this->sl_available,
            'vl_grant' => $this->vl_grant,
            'sl_grant' => $this->sl_grant,
            'vl_after' => $this->vl_after,
            'sl_after' => $this->sl_after,
            'already_granted' => (bool) $this->already_granted,
        ];
    }
}
