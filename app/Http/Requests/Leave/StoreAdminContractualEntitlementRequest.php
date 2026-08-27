<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the body for POST /api/admin/contractual-entitlements (confirm distribution).
 */
class StoreAdminContractualEntitlementRequest extends FormRequest
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
        $max = (string) config('leave.contractual_entitlement.max_per_type', '15.00');

        return [
            'employees' => ['required', 'array', 'min:1', 'max:500'],
            'employees.*.emp_id' => ['required', 'integer', 'min:1', 'distinct'],
            'employees.*.vl_to_add' => [
                'sometimes',
                'nullable',
                'numeric',
                'gt:0',
                'lte:'.$max,
                'regex:/^\d+(\.\d{1,2})?$/',
            ],
            'employees.*.sl_to_add' => [
                'sometimes',
                'nullable',
                'numeric',
                'gt:0',
                'lte:'.$max,
                'regex:/^\d+(\.\d{1,2})?$/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $employees = $this->input('employees');

            if (! is_array($employees)) {
                return;
            }

            foreach ($employees as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $vl = $row['vl_to_add'] ?? null;
                $sl = $row['sl_to_add'] ?? null;
                $vlEmpty = $vl === null || $vl === '';
                $slEmpty = $sl === null || $sl === '';

                if ($vlEmpty && $slEmpty) {
                    $validator->errors()->add(
                        'employees.'.$index,
                        'Provide vl_to_add and/or sl_to_add greater than 0.',
                    );
                }
            }
        });
    }
}
