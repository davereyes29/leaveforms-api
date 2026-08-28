<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim employee row for GET /api/admin/employees (report / filter pickers).
 *
 * Expects Employee with group loaded and effective group applied.
 */
class AdminEmployeeRefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->group;

        return [
            'id' => (int) $this->id,
            'employee_number' => (string) $this->id,
            'name' => $this->fullName(),
            'group' => $group === null ? null : [
                'id' => (int) $group->id,
                'abbreviation' => $group->abbreviation,
                'name' => $group->name,
            ],
        ];
    }
}
