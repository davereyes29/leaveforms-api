<?php

namespace App\Http\Requests\Leave;

use App\Models\Kdtph\Group;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/reports/employees-with-lwop.
 */
class IndexEmployeesWithLwopRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
        ];
    }
}
