<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim department_list row (`id` / `abbreviation` / `name`).
 */
class DepartmentRefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'abbreviation' => $this->abbreviation,
            'name' => $this->name,
        ];
    }
}
