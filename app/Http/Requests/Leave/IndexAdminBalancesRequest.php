<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Department;
use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates query parameters for GET /api/admin/balances (org-wide employee balances).
 */
class IndexAdminBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('employment_status') && is_string($this->employment_status)) {
            $this->merge([
                'employment_status' => strtolower($this->employment_status),
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
            'department_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Department::class, 'id'),
            ],
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
            'employment_status' => [
                'sometimes',
                'nullable',
                Rule::enum(EmploymentStatus::class),
            ],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $departmentId = $this->input('department_id');
            $groupId = $this->input('group_id');

            if ($departmentId === null || $departmentId === '' || $groupId === null || $groupId === '') {
                return;
            }

            $group = Group::query()->find((int) $groupId);

            if ($group === null || (int) $group->dept_id !== (int) $departmentId) {
                $validator->errors()->add(
                    'group_id',
                    'The selected group does not belong to the selected department.',
                );
            }
        });
    }
}
