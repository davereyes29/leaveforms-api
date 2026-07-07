<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\DayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date'        => ['required', 'date'],
            'end_date'          => ['required', 'date', 'after_or_equal:start_date'],
            'day_type'          => ['required', Rule::enum(DayType::class)],
            'leave_reason'      => ['required', 'string', 'max:2000'],
            'leave_attachment'  => ['nullable', 'string', 'max:255'],
        ];
    }
}
