<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/leaves/quarter-usage — wrapped as { data: { ... } }.
 *
 * @property array<string, mixed> $resource
 */
class VacationLeaveQuarterUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
