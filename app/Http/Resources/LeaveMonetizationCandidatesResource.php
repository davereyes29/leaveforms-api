<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/admin/reports/leave-monetization-candidates — wrapped as { data: { ... } }.
 *
 * Expects the array built by LeaveMonetizationCandidatesService.
 *
 * @property array<string, mixed> $resource
 */
class LeaveMonetizationCandidatesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
