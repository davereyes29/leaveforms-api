<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = $this->fldDate;

        return [
            'id' => (int) $this->fldID,
            'date' => $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : (string) $date,
            'name' => (string) $this->fldHoliday,
            'holiday_type' => (int) $this->fldHolidayType,
            // Matches LeaveDurationService: every kdtholiday row is a non-workday.
            'is_non_working' => true,
            'location_id' => (int) $this->fldLocID,
            'location' => (string) $this->fldLocation,
        ];
    }
}
