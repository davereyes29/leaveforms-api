<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/leaves/lwop — wrapped as { data: { ... } }.
 *
 * Expects the array built by EmployeesWithLwopReportService::forEmployee().
 *
 * @property array<string, mixed> $resource
 */
class EmployeeLwopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
