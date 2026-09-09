<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\DayType;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/leaves/quarter-usage — VL days in the overlapping quarter(s).
 */
class IndexVacationLeaveQuarterUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if ($code === null || $code === '') {
            $this->merge(['code' => 'VL']);
        } else {
            $this->merge(['code' => strtoupper(trim((string) $code))]);
        }

        if (! $this->filled('day_type')) {
            $this->merge(['day_type' => DayType::Full->value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emp_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', Rule::in(LeaveTypeRules::filableCodes())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'day_type' => ['required', Rule::enum(DayType::class)],
        ];
    }
}
