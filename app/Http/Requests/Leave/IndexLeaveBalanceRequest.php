<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/balances.
 *
 * FormRequest keeps validation out of the controller — Laravel runs
 * rules automatically before the controller method executes.
 */
class IndexLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth is already handled by portal.auth middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $this->merge([
                'code' => strtoupper($this->code),
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
            'code' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('leave_type', 'code'),
            ],
        ];
    }
}
