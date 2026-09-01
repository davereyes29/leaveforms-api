<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee row for GET/POST /api/admin/newly-regularized.
 *
 * Expects Employee with group.department loaded, effective group applied,
 * and transient entitlement fields set by AdminNewlyRegularizedService.
 */
class AdminNewlyRegularizedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee' => EmployeeNest::from($this->resource),
            'date_hired' => $this->date_hired?->toDateString(),
            'regularization_date' => $this->regularization_date,
            'months_of_service' => $this->months_of_service,
            'months_prorated' => $this->months_prorated,
            'vl_available' => $this->vl_available,
            'sl_available' => $this->sl_available,
            'ptb_available' => $this->ptb_available,
            'vl_grant' => $this->vl_grant,
            'sl_grant' => $this->sl_grant,
            'ptb_grant' => $this->ptb_grant,
            'vl_after' => $this->vl_after,
            'sl_after' => $this->sl_after,
            'ptb_after' => $this->ptb_after,
            'already_granted' => (bool) $this->already_granted,
        ];
    }
}
