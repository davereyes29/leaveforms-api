<?php

namespace App\Http\Requests\Leave;

use App\Enums\Leave\MonthlyAttendanceCutOff;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query parameters for GET /api/admin/reports/monthly-attendance.
 */
class IndexMonthlyAttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cut_off') && is_string($this->cut_off)) {
            $this->merge([
                'cut_off' => strtolower($this->cut_off),
            ]);
        }

        $empIds = $this->input('emp_ids');

        if (is_string($empIds)) {
            $empIds = preg_split('/\s*,\s*/', trim($empIds), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_numeric($empIds)) {
            $empIds = [$empIds];
        }

        if (is_array($empIds)) {
            $unique = [];

            foreach ($empIds as $id) {
                if ($id === null || $id === '') {
                    continue;
                }

                $unique[(int) $id] = $id;
            }

            $this->merge([
                'emp_ids' => array_values($unique),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxEmpIds = (int) config('leave.reports.monthly_attendance.max_emp_ids', 200);

        return [
            'month' => ['required', 'date_format:Y-m'],
            'cut_off' => ['required', Rule::enum(MonthlyAttendanceCutOff::class)],
            'emp_ids' => ['required', 'array', 'min:1', 'max:'.$maxEmpIds],
            'emp_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
                Rule::exists(Employee::class, 'id'),
            ],
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
