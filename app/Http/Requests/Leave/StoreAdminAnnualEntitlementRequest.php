<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body for POST /api/admin/annual-entitlements (confirm distribution).
 */
class StoreAdminAnnualEntitlementRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'emp_ids' => ['required', 'array', 'min:1', 'max:500'],
            'emp_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
