<?php

namespace App\Http\Requests\Leave;

use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/employees.
 */
class IndexAdminEmployeesRequest extends FormRequest
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
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
        ];
    }
}
