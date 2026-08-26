<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee row for GET/POST /api/admin/balances.
 *
 * Expects Employee with group.department loaded, effective group applied,
 * and transient employment_status + balance_rows set by AdminBalanceListService.
 */
class AdminEmployeeBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee' => EmployeeNest::from($this->resource),
            'employment_status' => $this->employment_status,
            'balances' => $this->balance_rows,
        ];
    }
}
