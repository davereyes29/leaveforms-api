<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\DayType;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if ($code === null || $code === '') {
            $this->merge(['code' => 'VL']);

            return;
        }

        $this->merge(['code' => strtoupper(trim((string) $code))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $code = (string) $this->input('code', 'VL');
        $fullDayOnly = LeaveTypeRules::requiresFullDay($code);

        return [
            'emp_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', Rule::in(LeaveTypeRules::filableCodes())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'day_type' => $fullDayOnly
                ? ['required', Rule::in([DayType::Full->value])]
                : ['required', Rule::enum(DayType::class)],
            'leave_reason' => ['required', 'string', 'max:2000'],
            ...(LeaveTypeRules::requiresAttachment($code)
                ? $this->requiredAttachmentRules()
                : $this->prohibitedForVlRules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $code = (string) $this->input('code', 'VL');

        return [
            'code.in' => 'Only Vacation Leave, Sick Leave, PTB Leave, Emergency Leave, and Long Leave can be filed for now.',
            'day_type.in' => 'This leave type can only be filed as a full day.',
            ...$this->prohibitedAttachmentMessages($code),
            ...$this->requiredAttachmentMessages($code),
        ];
    }
}
