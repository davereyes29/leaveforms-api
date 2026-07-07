<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\LeaveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->status)) {
            $this->merge([
                'status' => strtolower($this->status),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(LeaveStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
