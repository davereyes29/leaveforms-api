<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the body for POST /api/admin/balances (manual available-credit adjustment).
 *
 * Allowlist: VL, SL, EL, PTB. ML/PL are grant-on-submit; LL/SPL are not credit-grid types.
 */
class StoreAdminBalanceAdjustmentRequest extends FormRequest
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

        if ($this->has('remarks') && is_string($this->remarks)) {
            $this->merge([
                'remarks' => trim($this->remarks),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emp_id' => ['required', 'integer', 'min:1'],
            'code' => [
                'required',
                'string',
                Rule::exists('leave_type', 'code'),
            ],
            'available_delta' => ['required', 'numeric', 'regex:/^[+-]?\\d+(\\.\\d{1,2})?$/'],
            'remarks' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'available_delta.regex' => 'The available delta must have at most 2 decimal places.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('available_delta')) {
                $delta = $this->input('available_delta');

                if ($delta !== null && $delta !== ''
                    && bccomp(bcadd((string) $delta, '0', 2), '0', 2) === 0) {
                    $validator->errors()->add(
                        'available_delta',
                        'The available delta must not be zero.',
                    );
                }
            }

            if ($validator->errors()->has('code')) {
                return;
            }

            $code = strtoupper((string) $this->input('code'));

            if ($code === '') {
                return;
            }

            $message = LeaveTypeRules::manualAdjustmentRejection($code);

            if ($message !== null) {
                $validator->errors()->add('code', $message);
            }
        });
    }
}
