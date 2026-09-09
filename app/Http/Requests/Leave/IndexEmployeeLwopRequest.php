<?php

namespace App\Http\Requests\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters for GET /api/leaves/lwop.
 */
class IndexEmployeeLwopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('year')) {
            $tz = (string) config('leave.reminders.timezone', 'Asia/Manila');
            $this->merge([
                'year' => (int) CarbonImmutable::now($tz)->year,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emp_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
