<?php

namespace App\Http\Requests\Leave;

use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/contractual-entitlements.
 */
class IndexAdminContractualEntitlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q') && is_string($this->q)) {
            $this->merge([
                'q' => trim($this->q),
            ]);
        }

        if ($this->has('sort') && is_string($this->sort)) {
            $this->merge([
                'sort' => strtolower($this->sort),
            ]);
        }

        if ($this->has('emp_ids') && is_string($this->emp_ids)) {
            $ids = preg_split('/\s*,\s*/', trim($this->emp_ids), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $this->merge([
                'emp_ids' => array_values($ids),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emp_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'emp_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'sort' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
