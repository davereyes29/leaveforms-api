<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/leaves (org-wide All Leave Requests).
 *
 * Default statuses when omitted: pending_approval, pending_conversion, confirmed, converted_to_sl, converted_to_el.
 */
class IndexAdminLeavesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $this->merge([
                'code' => strtoupper($this->code),
            ]);
        }

        if ($this->has('status') && is_string($this->status)) {
            $this->merge([
                'status' => strtolower($this->status),
            ]);
        }

        if ($this->has('sort') && is_string($this->sort)) {
            $this->merge([
                'sort' => strtolower($this->sort),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('leave_type', 'code'),
            ],
            'status' => [
                'sometimes',
                'nullable',
                Rule::in([
                    LeaveStatus::PendingApproval->value,
                    LeaveStatus::PendingConversion->value,
                    LeaveStatus::Confirmed->value,
                    LeaveStatus::ConvertedToSl->value,
                    LeaveStatus::ConvertedToEl->value,
                ]),
            ],
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
            'sort' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
