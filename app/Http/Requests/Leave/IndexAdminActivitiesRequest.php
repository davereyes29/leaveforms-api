<?php

namespace App\Http\Requests\Leave;

use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/activities.
 */
class IndexAdminActivitiesRequest extends FormRequest
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
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'sort' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
