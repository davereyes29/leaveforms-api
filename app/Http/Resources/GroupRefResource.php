<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim group_list row (`id` / `abbreviation` / `name` / `dept_id`).
 */
class GroupRefResource extends JsonResource
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
            'dept_id' => (int) $this->dept_id,
        ];
    }
}
