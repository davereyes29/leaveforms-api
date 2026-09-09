<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\DayType;
use App\Enums\Leave\SpecialLeaveAssignmentType;
use App\Enums\Leave\SpecialLeaveReason;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['assignment_type', 'special_leave_reason'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => strtolower(trim($value))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $code = $this->leaveTypeCode();
        $fullDayOnly = LeaveTypeRules::requiresFullDay($code);

        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'day_type' => $fullDayOnly
                ? ['required', Rule::in([DayType::Full->value])]
                : ['required', Rule::enum(DayType::class)],
            'leave_reason' => ['required', 'string', 'max:2000'],
            ...$this->specialLeaveFieldRules($code),
            ...$this->prohibitedForVlRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $code = $this->leaveTypeCode();

        return [
            'day_type.in' => 'This leave type can only be filed as a full day.',
            'assignment_type.prohibited' => 'Assignment type is only used for Special Leave.',
            'special_leave_reason.prohibited' => 'A qualifying event is only used for Special Leave.',
            ...$this->prohibitedOnUpdateMessages($code),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specialLeaveFieldRules(string $code): array
    {
        if (strtoupper($code) === 'SPL') {
            return [
                'assignment_type' => ['required', Rule::enum(SpecialLeaveAssignmentType::class)],
                'special_leave_reason' => ['required', Rule::enum(SpecialLeaveReason::class)],
            ];
        }

        return [
            'assignment_type' => ['prohibited'],
            'special_leave_reason' => ['prohibited'],
        ];
    }

    private function leaveTypeCode(): string
    {
        $leave = $this->route('leave');

        if ($leave instanceof LeaveInfo) {
            $leave->loadMissing('leaveType');

            return strtoupper((string) ($leave->leaveType?->code ?? 'VL'));
        }

        return 'VL';
    }
}
