<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/admin/reports/employees-with-lwop — wrapped as { data: { ... } }.
 *
 * Expects the array built by EmployeesWithLwopReportService.
 *
 * @property array<string, mixed> $resource
 */
class EmployeesWithLwopReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
