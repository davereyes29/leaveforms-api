<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes one balance row for the JSON response.
 *
 * JsonResource sits between your data and what the client receives.
 * Controllers stay thin; formatting lives here.
 */
class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'leave_type_id' => $this->resource['leave_type_id'],
            'code' => $this->resource['code'],
            'name' => $this->resource['name'],
            'available' => $this->resource['available'],
            'reserved' => $this->resource['reserved'],
        ];
    }
}
