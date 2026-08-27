<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee row for GET/POST /api/admin/contractual-entitlements.
 *
 * GET: employee + current VL/SL available.
 * POST success: also includes before / grant / after for the confirm response.
 */
class AdminContractualEntitlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'employee' => EmployeeNest::from($this->resource),
            'vl_available' => $this->vl_available,
            'sl_available' => $this->sl_available,
        ];

        if (array_key_exists('vl_grant', $this->resource->getAttributes())) {
            $payload['vl_before'] = $this->vl_before;
            $payload['sl_before'] = $this->sl_before;
            $payload['vl_grant'] = $this->vl_grant;
            $payload['sl_grant'] = $this->sl_grant;
            $payload['vl_after'] = $this->vl_after;
            $payload['sl_after'] = $this->sl_after;
        }

        return $payload;
    }
}
