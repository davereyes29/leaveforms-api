<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\DayType;
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
            ...(LeaveTypeRules::requiresAttachment($code)
                ? $this->optionalAttachmentReplaceRules()
                : $this->prohibitedForVlRules()),
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
            ...$this->prohibitedAttachmentMessages($code),
            ...$this->requiredAttachmentMessages($code),
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
