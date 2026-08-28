<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/admin/reports/monthly-attendance — wrapped as { data: { ... } }.
 *
 * Expects the array built by MonthlyAttendanceReportService.
 *
 * @property array<string, mixed> $resource
 */
class MonthlyAttendanceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
